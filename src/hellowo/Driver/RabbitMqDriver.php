<?php

namespace ryunosuke\hellowo\Driver;

use Exception;
use Generator;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use ryunosuke\hellowo\Message;

/**
 * architecture:
 * nothing special, simply use RabbitMQ.
 */
class RabbitMqDriver extends AbstractDriver
{
    public static function isEnabled(): bool
    {
        return class_exists(AMQPChannel::class);
    }

    protected static function normalizeParams(array $params): array
    {
        return [
            'queue' => [
                'queue' => $params['path'] ?? null,
            ],
        ];
    }

    private AbstractConnection $connection;
    private AMQPChannel        $channel;

    private float  $waittime;
    private string $queue;
    private array  $queueDeclaration;
    private array  $consumeDeclaration;

    /** @var AMQPMessage[] */
    private array $buffer = [];

    public function __construct(array $options)
    {
        $options = self::normalizeOptions($options, [
            // AMQPConnection instance or AMQPConnectionConfig instance or AMQPConnectionConfig DSN
            'transport' => [
                'host'     => '127.0.0.1',
                'port'     => 5672,
                'username' => null,
                'password' => null,
            ],
            // basic_qos's args
            'qos'       => [
                'prefetch_size'  => 0,
                'prefetch_count' => 1,
                'a_global'       => false,
            ],
            /* Currently, only default
            'exchange'   => [
                'exchange'    => 'hellowo',
                'type'        => 'direct',
                'passive'     => false,
                'durable'     => true,
                'auto_delete' => false,
                'internal'    => false,
                'nowait'      => false,
                'ticket'      => null,
                'arguments'   => [],
            ],
            */
            // queue name or queue_declare' args
            'queue'     => [
                'queue'       => 'hellowo',
                'passive'     => false,
                'durable'     => true,
                'exclusive'   => false,
                'auto_delete' => false,
                'nowait'      => false,
                'ticket'      => null,
                'arguments'   => [
                    'x-max-priority' => 3,
                ],
            ],
            // basic_consume's args
            'consume'   => [
                'consumer_tag' => '',
                'no_local'     => false,
                'no_ack'       => false,
                'exclusive'    => false,
                'nowait'       => false,
                'ticket'       => null,
                'arguments'    => [],
            ],
            // one cycle wait time
            'waittime'  => 60.0,
        ]);

        // connection
        $transport = $options['transport'];
        if (is_array($transport)) {
            $config = new AMQPConnectionConfig();
            $config->setHost($transport['host']);
            $config->setPort($transport['port']);
            $config->setUser($transport['username']);
            $config->setPassword($transport['password']);
            $transport = $config;
        }
        if ($transport instanceof AMQPConnectionConfig) {
            $transport = AMQPConnectionFactory::create($transport);
        }
        $this->connection = $transport;

        // channel
        $this->channel = $this->connection->channel();
        $this->channel->basic_qos(...self::normalizeArguments([$this->channel, 'basic_qos'], $options['qos']));

        // queue
        $this->queue            = is_string($options['queue']) ? $options['queue'] : $options['queue']['queue'];
        $this->queueDeclaration = $options['queue'];

        // consumer
        $this->consumeDeclaration = $options['consume'];

        $this->waittime = $options['waittime'];

        $server = $this->channel->getConnection()->getServerProperties();
        parent::__construct("rabbitMQ {$server['cluster_name'][1]}");
    }

    protected function setup(bool $forcibly = false): void
    {
        if ($forcibly) {
            $this->channel->queue_delete($this->queueDeclaration['queue']);
        }

        $queue = $this->queueDeclaration;
        if (is_array($queue)) {
            if (is_array($queue['arguments'])) {
                $queue['arguments'] = new AMQPTable($queue['arguments']);
            }
            $this->channel->queue_declare(...self::normalizeArguments([$this->channel, 'queue_declare'], $queue));
        }
    }

    protected function daemonize(): void
    {
        $this->channel->basic_consume(...self::normalizeArguments([$this->channel, 'basic_consume'], array_replace($this->consumeDeclaration, [
            'queue'    => $this->queue,
            'callback' => function (AMQPMessage $msg) {
                $this->buffer[$msg->getDeliveryTag()] = $msg;
            },
        ])));

        parent::daemonize();
    }

    protected function select(): Generator
    {
        if (!$this->buffer) {
            try {
                $this->channel->wait(null, false, $this->waittime);
            }
            catch (AMQPTimeoutException $ex) {
                // do nothing
            }
        }

        foreach ($this->buffer as $id => $msg) {
            $retry = yield new Message($id, $msg->getBody(), 0);
            if ($retry === null) {
                unset($this->buffer[$id]);
                $this->channel->basic_ack($id);
            }
            else {
                /** @var AMQPTable $headers */
                $headers = $msg->get_properties()['application_headers'];
                $headers->set('x-delay', $retry * 1000, AMQPTable::T_INT_LONG);

                unset($this->buffer[$id]);
                $this->channel->basic_reject($id, true);
            }
            return;
        }
    }

    protected function error(Exception $e): bool
    {
        // no reconnect because it is better to die and be revived by process manager than to prolong life.
        return !$this->channel->getConnection()->isConnected();
    }

    protected function close(): void
    {
        $this->channel->basic_recover(true);
        $this->channel->close();
        unset($this->channel);

        $this->connection->close();
        unset($this->connection);

        gc_collect_cycles();
    }

    protected function send(
        string $contents,
        ?int $priority = null,
        ?float $delay = null,
        array $properties = [],
        ?string $exchange = null,
        ?string $routing_key = null
    ): ?string {
        $properties = array_replace_recursive([
            'delivery_mode'       => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'priority'            => $priority ?? 2,
            'application_headers' => [
                'x-delay' => ($delay ?? 0) * 1000,
            ],
        ], $properties);

        if (is_array($properties['application_headers'])) {
            $properties['application_headers'] = new AMQPTable($properties['application_headers']);
        }

        $message = new AMQPMessage($contents, $properties);
        return $this->channel->basic_publish($message, $exchange, $routing_key ?? $this->queue);
    }

    protected function clear(): int
    {
        $count = 0;
        while (true) {
            try {
                $this->channel->wait(null, false, 0.1);
                foreach ($this->buffer as $id => $msg) {
                    $count++;
                    unset($this->buffer[$id]);
                    $this->channel->basic_ack($id);
                }
            }
            catch (AMQPTimeoutException $ex) {
                break;
            }
        }
        //$this->channel->queue_purge($this->queue);
        return $count;
    }
}
