#!/bin/bash
# filepath: insert_syslog_test_data.sh

# Number of iterations
ITERATIONS=100


SQL_ALERT_RULE_INSERT="INSERT INTO syslog_alert (id,hash,name,severity,method,level,num,type,enabled,repeat_alert,open_ticket,message,body,user,date,email,notify,command,notes)
                        VALUES (1,'8f440030d3425e37cb66e5df54902bb0','interface down alert',1,0,1,1,'messageb','on',0,'','interface down','admin',1767376990,NULL,0,NULL,NULL);"

SQL_REMOVAL_RULE_INSERT="INSERT INTO syslog_remove (id,hash,name,type,enabled,method,message,user,date,notes)
                          VALUES (1,'0faf589f7b6b7da1bcec40b92340487d','exploded','messageb','on','del',
                          'the box exploded','admin',1767376604,NULL);"


SYSLOG_EVENTS_INSERT="INSERT INTO syslog_incoming (facility_id, priority_id, program, logtime, host, message, status) VALUES 
    (1, 1, 'app1', NOW(), 'bob', 'interface down', 0),
    (2, 3, 'app2', NOW(), 'alice', 'cpu high', 1),
    (1, 2, 'app1', NOW(), 'router_1', 'the box exploded', 0);"


echo "Starting insert of $ITERATIONS batches (30,000 total records)..."
echo "Start time: $(date)"

# MySQL connection parameters
export MYSQL_PWD="cactiuser"
MYSQL_CMD="mysql -h 127.0.0.1 -u cactiuser cacti"

# Create Rules

echo "Creating alert and removal rules..."
$MYSQL_CMD -e "$SQL_ALERT_RULE_INSERT"

echo "Creating removal rule..."
$MYSQL_CMD -e "$SQL_REMOVAL_RULE_INSERT"


# Create test syslog events
for i in $(seq 1 $ITERATIONS); do
    $MYSQL_CMD -e "$SYSLOG_EVENTS_INSERT"
    
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
