#!/bin/sh

# testing many-to-many (multi worker and multi client)

trap 'kill $(jobs -p)' EXIT

DAEMON=5  # worker count
CLIENT=13 # client count
STRESS=2  # stress count
TOTAL=257 # request count

URL=$1
BASEDIR=$(dirname $(readlink -f $0))
DRIVER="$BASEDIR/api.php $URL"

WORKDIR=/tmp/integration
mkdir -p $WORKDIR
touch $WORKDIR/dummy.log
rm $WORKDIR/*.log

# check arguments and clear
if ! php $DRIVER clear; then
  exit
fi

# ready worker
for i in $(seq 1 $DAEMON)
do
  php $DRIVER worker 1>$WORKDIR/$i-stdout.log 2>$WORKDIR/$i-stderr.log &
done
sleep 1 # wait until main loop

# ready stress
for i in $(seq 1 $STRESS)
do
  php -r "while(true);" &
done

# requests
seq 1 $(($TOTAL - 1)) | xargs -I {} -P $CLIENT php $DRIVER client {} 1 0
php $DRIVER client finish 0 1

# wait for finish request
for s in $(seq 1 10)
do
  for i in $(seq 1 $DAEMON)
  do
    if grep -q finish $WORKDIR/$i-stdout.log; then
        break 2
    fi
  done
  sleep 0.2
done

# print stat
JOBS=0
DONES=0
FAILS=0
OUTPUTS=0
RESULT=""
for i in $(seq 1 $DAEMON)
do
  JOB=$(cat $WORKDIR/$i-stderr.log | grep "job:" | wc -l)
  JOBS=$(($JOBS + $JOB))
  DONE=$(cat $WORKDIR/$i-stderr.log | grep "done:" | wc -l)
  DONES=$(($DONES + $DONE))
  FAIL=$(cat $WORKDIR/$i-stderr.log | grep "fail:" | wc -l)
  FAILS=$(($FAILS + $FAIL))
  OUTPUT=$(cat $WORKDIR/$i-stdout.log | wc -l)
  OUTPUTS=$(($OUTPUTS + $OUTPUT))
  RESULT="$RESULT\n${URL}-${i} $JOB $DONE $FAIL $OUTPUT"
done

{
  echo -e "worker job   done   fail   output"
  echo -e "$RESULT"
  echo -e "total  $JOBS $DONES $FAILS $OUTPUTS"
} | column -t
