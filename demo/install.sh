#!/bin/sh
WORKERFILE="$(dirname $(realpath $0))/worker.php"

cat << EOS > /etc/systemd/system/hellowo.service
[Unit]
After=network.target

[Service]
Type=simple
ExecStartPre=/bin/mkdir -p /var/log/hellowo
ExecStart=/bin/sh -c "exec /usr/bin/php -d disable_functions=register_shutdown_function $WORKERFILE 1>/var/log/hellowo/stdout.log 2>/var/log/hellowo/stderr.log"
ExecReload=/usr/bin/kill -HUP \$MAINPID

[Install]
EOS

systemctl daemon-reload
systemctl start hellowo.service
