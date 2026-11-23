#!/usr/bin/env python3
# +-------------------------------------------------------------------------+
# | Copyright (C) 2004-2026 The Cacti Group                                 |
# |                                                                         |
# | This program is free software; you can redistribute it and/or           |
# | modify it under the terms of the GNU General Public License             |
# | as published by the Free Software Foundation; either version 2          |
# | of the License, or (at your option) any later version.                  |
# |                                                                         |
# | This program is distributed in the hope that it will be useful,         |
# | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
# | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
# | GNU General Public License for more details.                            |
# +-------------------------------------------------------------------------+
"""Build docs/<vendor>.json.gz recommendation catalogues from extracted JSON.

Each input is a syslog-recommendations JSON document extracted from the
vendor's public message reference (see docs/RECOMMENDATION_SOURCES.md). The
build drops records the importer would reject (neither an explanation nor a
recommended action), case-insensitive duplicate identifiers (first kept) and
the generic identifiers listed in EXCLUDE, then writes reproducible gzip.

Usage: build_catalogues.py SOURCE_DIR DOCS_DIR VENDOR [VENDOR ...]
       (reads SOURCE_DIR/<vendor>.json, writes DOCS_DIR/<vendor>.json.gz)
"""

import gzip
import json
import os
import sys

FIELDS = ['vendor', 'identifier', 'component', 'severity', 'message',
          'explanation', 'recommended_action', 'reference']

# Identifiers that are ordinary words or protocol terms other vendors also log
# as a single token; they would show this vendor's advice for unrelated messages.
EXCLUDE = {
    'paloalto': {
        'admin-down', 'agent-event', 'auth-fail', 'auth-success', 'cache-cleared',
        'cert-expiry', 'connect-client', 'connect-fail', 'expired-time', 'fan-failure',
        'fan-inserted', 'fan-removed', 'if-add', 'if-clear', 'if-del', 'lacp-down',
        'lacp-up', 'lease-end', 'lease-start', 'link-change', 'link-down', 'load-success',
        'nego-fail', 'neighbor-down', 'nh-down', 'nh-up', 'ps-failure', 'ps-inserted',
        'relay-on', 'server-on', 'slot-up', 'speed-duplex', 'state-change', 'unresponsive',
    },
    'mikrotik': {
        '3way', 'cme', 'cmd-failed', 'conntrack', 'fcs', 'no-proposal-chosen', 'ph1', 'phase1',
    },
}


def build(source_dir, docs_dir, vendor):
    with open(os.path.join(source_dir, vendor + '.json'), encoding='utf-8') as fh:
        records = json.load(fh)['recommendations']

    seen, out, empty, excluded, duplicate = set(), [], 0, 0, 0

    for record in records:
        if not (record.get('explanation') or '').strip() and not (record.get('recommended_action') or '').strip():
            empty += 1
            continue

        key = record['identifier'].lower()

        if key in EXCLUDE.get(vendor, ()):
            excluded += 1
            continue

        if key in seen:
            duplicate += 1
            continue

        seen.add(key)
        out.append({field: record.get(field, None if field == 'severity' else '') for field in FIELDS})

    text = json.dumps({'format': 'syslog-recommendations', 'version': 1, 'recommendations': out},
                      indent=2, ensure_ascii=False) + '\n'

    with open(os.path.join(docs_dir, vendor + '.json.gz'), 'wb') as fh:
        # A fixed mtime and no file name keep rebuilt files byte-identical.
        with gzip.GzipFile(filename='', mode='wb', fileobj=fh, compresslevel=9, mtime=0) as gz:
            gz.write(text.encode('utf-8'))

    print('%s: kept %d, no text %d, excluded %d, duplicate %d' % (vendor, len(out), empty, excluded, duplicate))


def main(argv):
    if len(argv) < 4:
        sys.stderr.write(__doc__)
        return 2

    for vendor in argv[3:]:
        build(argv[1], argv[2], vendor)

    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
