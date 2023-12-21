
#### Create by Sean Mancini sean@seanmancini.com www.seanmancini.com
### usage enter  /opt/snmptt_syslog.py --hostname $aA --alert "$Fz" --priority 6 --facility 2  in your cacti syslog command execution 
## this will write the messege direct to the cacti syslog incoming table to be ingested into the syslog database



#!/usr/bin/python3

### connect to mysql database

### import modules
import mysql.connector
import sys
from datetime import datetime
import argparse
import dotenv
import os


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
    ### read database credentials from .env file
    ### create .env file in same directory as script
    dotenv.load_dotenv(dotenv.find_dotenv())
    db = mysql.connector.connect(
        host=os.getenv("MYSQL_HOST"),
        user=os.getenv("MYSQL_USER"),
        passwd=os.getenv("MYSQL_PASS"),
        database=os.getenv("MYSQL_DB")
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
