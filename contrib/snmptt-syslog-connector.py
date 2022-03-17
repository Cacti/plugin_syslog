
#!/usr/bin/python3

#### Created by Sean Mancini sean@seanmancini.com www.seanmancini.com
### usage enter  /opt/cacti-syslog-connector.py --hostname $aA --alert "$Fz" --priority 6 --facility 2  in you SNMPTT EXEC config line
### EXEC /opt/snmptt_syslog.py --hostname $aA --alert "$Fz" --priority 6 --facility 2 ( Change variables as you see fit)
## this will write the message directly to the cacti syslog incoming table to be ingested into the syslog database




### connect to mysql database

### import modules
import mysql.connector
import sys
from datetime import datetime
import argparse


# Define Arguments for variables from snmptt
parser = argparse.ArgumentParser(description = 'SNMPTT Variables')

parser.add_argument('--hostname', help = "Device Hostname", required=True)
parser.add_argument('--alert', help = "Alert Messege", required=True)
parser.add_argument('--facility', help = "Alert Facility", required=True)
parser.add_argument('--priority', help = "Alert Priority", required=True)



args = parser.parse_args(sys.argv[1:])

if args.hostname is not None: ({'device Hostname': args.hostname}) #snmp timeout
if args.alert is not None: ({'Alert Messege': args.alert}) #device template
if args.facility is not None: ({'Alert facility': args.facility}) #device template
if args.priority is not None: ({'Alert priority': args.priority}) #device template



### connect to database
try:
    db = mysql.connector.connect(
        host = "localhost",
        user = "",
        passwd = "",
        database = ""
    )

    ### create cursor
    cursor = db.cursor()

    ### write to database
    sql = "INSERT INTO syslog_incoming (facility_id, priority_id, program, logtime, host, message) VALUES (%s, %s, %s, %s ,%s, %s)"
    val = (args.facility,args.priority, "traps", datetime.now(),args.hostname, args.alert)
    cursor.execute(sql, val)

    ### commit changes
    db.commit()

    ### close database
    db.close()

    ### print success message
    print("Successfully wrote to database")
except:
    ### print error message
    print("Unable to write to database")
    sys.exit(1)
