#!/bin/bash
# filepath: insert_syslog_test_data.sh

# Number of iterations
ITERATIONS=100

# SQL statement to execute
SQL_INSERT="INSERT INTO syslog_incoming (facility_id, priority_id, program, logtime, host, message, status) VALUES 
    (1, 1, 'app1', NOW(), 'bob', 'interface down', 0),
    (2, 3, 'app2', NOW(), 'alice', 'cpu high', 1),
    (1, 2, 'app1', NOW(), 'router_1', 'the box exploded', 0);"

echo "Starting insert of $ITERATIONS batches (30,000 total records)..."
echo "Start time: $(date)"

# MySQL connection parameters
export MYSQL_PWD="cactiuser"
MYSQL_CMD="mysql -h 127.0.0.1 -u cactiuser cacti"

# Loop and insert
for i in $(seq 1 $ITERATIONS); do
    $MYSQL_CMD -e "$SQL_INSERT"
    
    # Show progress every 100 iterations
    if [ $((i % 100)) -eq 0 ]; then
        echo "Progress: $i / $ITERATIONS batches inserted"
    fi
done

echo "Completed!"
echo "End time: $(date)"
echo "Total records inserted: $((ITERATIONS * 3))"

# Verify insertion
echo "Verifying record count:"
$MYSQL_CMD -e "SELECT COUNT(*) as total_records FROM syslog_incoming;"
