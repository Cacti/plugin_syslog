# Recommendation Catalogue Sources

The `docs/<vendor>.json.gz` files are catalogues in the `syslog-recommendations` format (version 1) that can be
imported manually. Each was built from public vendor documentation. No login-protected portals were used.

- Explanations and recommended actions are condensed from the vendor's text. For **Fortinet**, and for most
  **Palo Alto Networks** records, the vendor documents only message formats, so the explanations are short
  sentences generated from the documented message text, type/subtype and severity.
- Each record's `reference` field links to the document or page it came from.
- Retrieval dates are the dates the documents were fetched.

Catalogues that are not covered by this build:

- `docs/cisco.json.gz`: Cisco CEMDB data via php-syslog-ng. It is seeded automatically.
- `docs/juniper.json.gz`: Juniper System Log Explorer, 14 curated EX Series `ESWD` records.

## Build

The final step is `contrib/recommendations/build_catalogues.py`. It is run once per vendor, with the
extracted JSON on standard input (`build_catalogues.py hpe < hpe.json`), and writes `docs/<vendor>.json.gz`.
For each vendor it:

1. Reads the extracted `<vendor>.json` (envelope `{"format":"syslog-recommendations","version":1,"recommendations":[...]}`).
2. Drops records that have neither an `explanation` nor a `recommended_action` (both empty after trimming),
   because the importer rejects them.
3. Drops the vendor's excluded identifiers (listed below), compared case-insensitively.
4. Drops case-insensitive duplicate identifiers. The first occurrence is kept.
5. Keeps these 8 fields, in this order: `vendor`, `identifier`, `component`, `severity`, `message`,
   `explanation`, `recommended_action`, `reference`. Missing fields become `""`, or `null` for `severity`.
6. Writes UTF-8 JSON with 2-space indentation (`ensure_ascii=False`) and a trailing newline, compressed with
   gzip level 9, `mtime=0` and an empty file name, so the output is reproducible.

Excluded identifiers. These are generic words that would match other vendors' messages:

| Vendor | Excluded identifiers |
|---|---|
| Palo Alto Networks (34) | `admin-down`, `auth-fail`, `auth-success`, `link-down`, `link-change`, `lacp-down`, `lacp-up`, `state-change`, `neighbor-down`, `connect-fail`, `connect-client`, `nh-down`, `nh-up`, `speed-duplex`, `unresponsive`, `fan-failure`, `fan-removed`, `fan-inserted`, `ps-failure`, `ps-inserted`, `slot-up`, `lease-start`, `lease-end`, `cert-expiry`, `if-add`, `if-del`, `if-clear`, `relay-on`, `server-on`, `expired-time`, `load-success`, `nego-fail`, `cache-cleared`, `agent-event` |
| MikroTik (8) | `ph1`, `phase1`, `conntrack`, `cme`, `fcs`, `cmd-failed`, `no-proposal-chosen`, `3way` |
| Other vendors | none |

The lookup tokenizer regex used to check every identifier is `%?[A-Za-z0-9_](?:[A-Za-z0-9_.\/-]*[A-Za-z0-9_])?`.

## Arista

**Records: 1,047** (95 facilities, all EOS).

| Document | Release | URL | Retrieved |
|---|---|---|---|
| EOS System Message Guide (`SysMsgGuide.pdf`), Dec 3, 2015. Primary source, 1,046 entries | EOS 4.15.3F | https://archive.org/details/manualzilla-id-5674789 | 2026-11-20 |
| EOS System Message Guide, Sep 26, 2015. Adds 1 entry (`%VXLAN-4-DYNA_VLANVNI_INVALID_PORT_NAME`) | EOS 4.15.2F | https://archive.org/details/manualzilla-id-5830366 | 2026-11-20 |
| EOS manual, system event logging (confirms where the guide is distributed) | current | https://www.arista.com/en/um-eos/eos-system-event-logging | 2026-11-20 |

- **Identifier:** `%FACILITY-<severity>-MNEMONIC`. The trailing `:` is dropped by the tokenizer.
  `Nov 20 17:01:02 leaf1 Ebra: %LINEPROTO-5-UPDOWN: Line protocol on Interface Ethernet1, changed state to down`
  gives `%LINEPROTO-5-UPDOWN`.
- **Severity:** the guide's severity word maps to RFC 5424: Emergency 0, Alert 1, Critical 2, Error 3, Warning 4,
  Notice 5, Info 6, Debug 7.
- **Gaps and notes:**
  - Arista publishes current guides only behind its login-protected download portal, so Internet Archive copies
    of the EOS 4.15 (2015) guide were used. Messages added after 4.15 are missing, and some may have changed.
  - CloudVision and other non-EOS messages are not included.
  - The PDF draws mnemonic underscores as rules. They were restored from word gaps (pdfminer.six) and the
    letters cross-checked with pypdf.
  - Line-break hyphenation was repaired heuristically.
  - `%DATAPLANE-4-MIRROR_DESTINATION_LAG_RESOURCE_FULL` has an empty explanation in the guide, which is kept.
    Some mnemonics that look truncated are reproduced as published (for example under VRRP).

## Dell

**Records: 7,142**: PowerEdge iDRAC/CMC 6,811; SmartFabric OS10 327; Networking OS9 4.

| Document | Version | URL | Retrieved |
|---|---|---|---|
| PowerEdge Servers Error and Event Messages Reference Guide (EEMI) | iDRAC9 / Lifecycle Controller v6.x | https://www.dell.com/support/manuals/en-us/idrac9-lifecycle-controller-v6.x-series/error_event_message_guide/Getting-help (PDF: https://dl.dell.com/content/manual64973867-poweredge-servers-error-and-event-messages-reference-guide.pdf) | 2026-11-20 |
| SmartFabric OS10 System Log Message Reference | rev. A06, up to 10.6.0.2 | https://www.dell.com/support/manuals/en-us/smartfabric-os10-emp-partner/os10-syslog-reference-guide/view-system-logs | 2026-11-20 |
| Dell EMC Networking OS Configuration Guide for the S5048F-ON (overtemperature and syslog-server pages) | OS9 9.14.2.8 | linked per record | 2026-11-20 |

- **Identifiers and example lines:**
  - iDRAC uses the EEMI MessageID:
    `<171>Severity: Critical, Category: System Health, MessageID: PSU0003, Message: ...` gives `PSU0003`.
  - OS10 uses `%EVENT_NAME`. The lookup tries both the `%`-prefixed and the bare form.
    `... %log-alert:EQM_MORE_PSU_FAULT: More power supply unit (PSU) fault ...` gives `EQM_MORE_PSU_FAULT`.
    For `INFRA_AFS` sub-events, the second token is the identifier (for example `MAC_LEARN_LIMIT_VIOLATION`).
  - OS9 uses `%FACILITY-SEV-MNEMONIC`, for example `%CHMGR-2-MAJOR_TEMP`.
- **Severity:**
  - iDRAC: Critical 2, Warning 4, Informational 6.
  - OS10: CRITICAL 2, WARNING 4, INFORMATIONAL 5. OS10 emits informational events as `%log-notice`.
  - OS9: the digit in the mnemonic.
- **Filtering and gaps:**
  - iDRAC: only messages whose alert types include Remote System Log are included (6,811 of about 9,865 IDs).
    If an ID appears in more than one category, the first occurrence is kept.
  - OS10: event names that cover several messages are merged, with variants joined by ` | ` and the worst
    severity used. CLI errors without an event name are excluded.
  - OS9: there is no public message reference, so only the 4 messages explained in the configuration guide are
    included.
  - PowerSwitch N-series (OS6) is excluded: its messages have no stable identifiers.
  - dell.com/support/manuals returns 403 to scripts. The content came from the public `dl.dell.com` topic HTML,
    read through a browser session.

## Fortinet

**Records: 1,462**: traffic 23, event 1,165, UTM 274. There were no skips or duplicates.

| Document | Version | URL | Retrieved |
|---|---|---|---|
| FortiOS Log Message Reference (one page per log message) | FortiOS 7.6.8 | https://docs.fortinet.com/document/fortigate/7.6.8/fortios-log-message-reference | 2026-11-21 |
| Log ID numbers | 7.6.8 | https://docs.fortinet.com/document/fortigate/7.6.8/fortios-log-message-reference/34314/log-id-numbers | 2026-11-21 |
| Log ID definitions (type/subtype codes) | 7.6.8 | https://docs.fortinet.com/document/fortigate/7.6.8/fortios-log-message-reference/656858/log-id-definitions | 2026-11-21 |
| FortiOS priority levels | 7.6.8 | https://docs.fortinet.com/document/fortigate/7.6.8/fortios-log-message-reference/446022/fortios-priority-levels | 2026-11-21 |

- **Identifier:** the 10-digit `logid` value with leading zeros: 2-digit type + 2-digit subtype (the UTM
  `eventtype`) + 6-digit message ID. In `... logid="0100032001" type="event" subtype="system" ...`, the token is
  `0100032001`.
- **Severity:** the documented level: Emergency 0, Alert 1, Critical 2, Error 3, Warning 4, Notice 5,
  Information 6, Debug 7.
- **Gaps and notes:**
  - The full 10-digit IDs are not printed in the reference. They are derived from the documented codes and were
    checked against known IDs (`0000000013`, `0100032001`, `0211008192`, `0316013056`, `0419016384`,
    `0720018432`, `1059028704`).
  - `message` is the documented Message Meaning (the `logdesc` value).
  - `explanation` is generated from the type/subtype, logid, ID name, meaning and level.
  - `recommended_action` is always empty because the reference documents no operator actions.
  - Fortinet notes that `level` is not always uniform. Bare numeric IDs can collide with other numbers, so rely
    on vendor scoping.

## HPE

**Records: 4,883**. Comware 7 412, Comware 5 846, AOS-CX and ArubaOS-Switch as listed below. The source file
had 5,578 records: the build drops the 694 without a description and one case-only duplicate
(`%10QOS/4/QOS_POLICY_APPLYVLAN_CBFAIL`, kept as the Comware 7 `%10QoS/...` record).

| Document | Version | Doc ID | URL | Retrieved |
|---|---|---|---|---|
| HP 5920 & 5900 Switch Series System Log Messages Reference (2014) | Comware 7 | c04532301 | https://support.hpe.com/hpesc/public/docDisplay?docId=c04532301 | 2026-11-20 (re-fetched 2026-11-21) |
| HP FlexFabric 12900 Switch Series System Log Messages Reference (2014); 8 records not in the 5900 guide | Comware 7 | c04532292 | https://support.hpe.com/hpesc/public/docDisplay?docId=c04532292 | 2026-11-20 (re-fetched 2026-11-21) |
| HP 5500 EI & 5500 SI Switch Series System Log Messages Reference (2014); only messages not in Comware 7 | Comware 5 | c04532298 | https://support.hpe.com/hpesc/public/docDisplay?docId=c04532298 | 2026-11-20 (re-fetched 2026-11-21) |
| AOS-CX Event Log Message Reference Guide (All Switch Series) | AOS-CX 10.17 | sd00007159en_us | https://support.hpe.com/hpesc/public/docDisplay?docId=sd00007159en_us | 2026-11-20 (re-fetched 2026-11-21) |
| Aruba Event Log Message Reference Guide for ArubaOS-Switch | AOS-S 16.10 | a00093582en_us | https://support.hpe.com/hpesc/public/docDisplay?docId=a00093582en_us | 2026-11-20 (re-fetched 2026-11-21) |

- **Identifiers:**
  - Comware: `%10MODULE/LEVEL/MNEMONIC`.
    `<187>Nov 20 17:00:00 2026 SW1 %%10IFNET/3/PHY_UPDOWN: Ethernet0/0: link status is down.` gives
    `%10IFNET/3/PHY_UPDOWN`. The tokenizer allows only one leading `%`.
  - AOS-CX: the plain event ID.
    `... Event|4701|LOG_INFO|||User user1 added user2 ...` gives `4701`.
  - AOS-S: the event ID zero-padded to 5 digits.
    `<14>Jan  1 00:15:35 10.0.0.2 00076 ports: port 2 is now on-line` gives `00076`.
- **Severity:**
  - Comware: the documented level 0-7. For 11 Comware 5 messages whose severity row disagrees with the example,
    both token forms are present.
  - AOS-CX: EMERG 0 … DEBUG 7.
  - AOS-S: Information 6, Warning 4, Debug 7, and Fatal 2 (an interpretation). AOS-S "None" and "Standard" are
    `null`.
- **Filtering and gaps:**
  - **694 events are omitted** (689 ArubaOS-Switch, 5 AOS-CX) because the guides give no description and no
    action for them. The source blocks were re-checked: the tables end at Message/Platforms/Category/Severity
    with no Description row. For example, AOS-S `00213`–`00215`, `03077`, `05316`, and AOS-CX `405`, `8809` and
    `5401`–`5403`. The importer would reject them.
  - **Comware 5 parsing fix:** in the narrow label column of the 5500 PDF, labels wrap mid-word (`Explanati`/`on`,
    `Recommende`/`d action`). The original parser left 5 records empty and put the action text into the
    explanation of 28 others. A re-parse that tolerates line breaks inside labels, keyed by MODULE/MNEMONIC,
    fixed these 33 records. The 5900 and 12900 guides have no split labels.
  - The AOS-CX and AOS-S guides contain no recommended actions, so `recommended_action` is empty for those
    families.
  - The Comware guides are 2014 HP editions. No newer public HPE Comware log references were found, and H3C
    guides were not used.
  - Comware 5 mnemonics that contain spaces were skipped.
  - HPE iLO and server messages are out of scope.
  - Numeric AOS-CX/AOS-S IDs can collide with other numbers in a message.
  - arubanetworking.hpe.com returns 403 to scripts. All documents were fetched through the public HPE Support
    Center API.

## Huawei

**Records: 3,686** (about 180 modules).

| Priority | Document | Release | nid | Records | Retrieved |
|---|---|---|---|---|---|
| 1 | CloudEngine S16700 Log Reference | V600R024C10 | EDOC1100459363 | 1,689 | 2026-11-20 |
| 2 | CloudEngine 9800, 8800, and 6800 Log Reference (only logs not in #1) | V300R026C00 | EDOC1100613732 | 296 | 2026-11-20 |
| 3 | S1720, S2700, S5700, and S6720 Log Reference | V200R011C10 | EDOC1000178180 | 1,701 | 2026-11-20 |

- References use the form `https://support.huawei.com/enterprise/en/doc/<nid>/<topicId>/<slug>`. VRP5 records
  link to the module page.
- **Identifier:** `%01MODULE/SEVERITY/MNEMONIC`, with the mnemonic's case kept as documented.
  `2020-08-18 18:08:56 HUAWEI %%01CLI/5/LOGIN(l):CID=2160731923;VTY0 login from 192.168.0.1 succeed.` gives
  `%01CLI/5/LOGIN`.
- **Severity:** the level in the mnemonic (0-7), which is already RFC 5424.
- **Gaps and notes:**
  - support.huawei.com returned 403 (Akamai) from the build host. The same documents were fetched from Huawei's
    regional mirror of the documentation API at `https://support.huawei.ru/supportgateway/view/v1/enterprise/doc/...`.
    About 2,130 pages were fetched, 1.5 s apart.
  - The CE V200R005C10 (EDOC1100074768), CE V200R021C00 (EDOC1100219294) and AR600/AR6000 V300R021
    (EDOC1100203218) references return "unreleased" or are blocked, so there are no AR- or NE-specific documents.
  - The WLAN and PKI modules of the V600/V300 documents were skipped.
  - If the same identifier is in more than one document, the higher-priority document wins. Within a VRP5 module,
    the first variant is kept.
  - `FECD/5/PARITY ERROR` was skipped because its mnemonic contains a space.
  - Lines with a format version other than `%%01`, or without the `%%dd` header, will not match.

## MikroTik

**Records: 11.** MikroTik publishes no catalogue of RouterOS log messages, and RouterOS has no message IDs.
MikroTik support confirmed that "such a list is not something we can provide" (forum thread
https://forum.mikrotik.com/t/271114). Records exist only where a documented message contains a distinctive
single token.

| Document | URL | Records used | Retrieved |
|---|---|---|---|
| IPsec (Troubleshooting/FAQ) | https://help.mikrotik.com/docs/spaces/ROS/pages/11993097/IPsec | `hashtype`, `enctype` | 2026-11-21 |
| Ethernet (Forced Mode) | https://help.mikrotik.com/docs/spaces/ROS/pages/8323191/Ethernet | `auto-initialization` | 2026-11-21 |
| PoE-Out (PoE-Out Logs) | https://help.mikrotik.com/docs/spaces/ROS/pages/19136769/PoE-Out | `hw-limit`, `class-limit` | 2026-11-21 |
| Log | https://help.mikrotik.com/docs/spaces/ROS/pages/328094/Log | `deassigned`, `KeepaliveTimer` | 2026-11-21 / 2026-11-22 |
| manual.mikrotik.com VRRP page (v7.26) | manual.mikrotik.com | `CTSYNC` | 2026-11-22 |
| manual.mikrotik.com Bridging and Switching page | manual.mikrotik.com | `bpdu-guard` | 2026-11-22 |
| manual.mikrotik.com PoE-out page | manual.mikrotik.com | `current-too-low`, `voltage-on-poe-in` | 2026-11-22 |

- **Identifier:** a distinctive token in the message text.
  `interface,warning sfp-sfpplus1 module auto-initialization failed, try forced-mode` gives `auto-initialization`.
- **Severity:** the topic in the documented example line maps to critical 2, error 3, warning 4, info 6,
  debug 7. Severity is `null` when the documented line has no severity topic (`hashtype`, `enctype`). The actual
  syslog severity depends on `/system logging action syslog-severity`.
- **Filtering and gaps:**
  - The source had 19 records. The build excludes 8 identifiers that are too generic: `ph1`, `phase1`,
    `CONNTRACK`, `CME`, `fcs`, `cmd-failed`, `NO-PROPOSAL-CHOSEN`, `3way`.
  - Most RouterOS messages are plain English (login failure, link up/down, assigned), and topic names appear on
    every line, so neither can be used as an identifier.
  - The forum, third-party parsers, changelogs and `.npk` binaries were not used as sources.
  - The PoE status template comes from the documented `status: on` example. `KeepaliveTimer` is a v6-era debug
    line.
  - Wording can differ between RouterOS v6 and v7. Several records have no documented action.

## Nokia

**Records: 2,016** (83 SR OS applications).

| Document | Release | Document ID | URL | Retrieved |
|---|---|---|---|---|
| SR OS Log Events Guide (HTML, one page per application) | 25.10.R1 | — | https://documentation.nokia.com/sr/25-10/7750-sr/titles/log-events.html | 2026-11-21 |
| SR OS Log Events Guide (PDF; used only to check the event count, 2,044 tables) | 25.10.R1 | 3HE 21208 AAAD TQZZA 01 | https://documentation.nokia.com/sr/25-10/7750-sr/pdf/Log_Events_Guide_25.10.R1.pdf | 2026-11-21 |
| System Management Guide, "Syslog implementation overview" | 22.2.R1 | — | https://infocenter.nokia.com/public/7750SR222R1A/topic/com.nokia.System_Mgmt_Guide/syslog_implemen-ai9exj5x1i.html | 2026-11-21 |

- References point to the application page plus the event anchor, for example `log-snmp.html#evnt1623`.
- **Identifier:** `APPLICATION-SEVERITY-EventName-EventID`, using the default severity.
  `<188>Jan  2 18:43:23 10.221.38.108 TMNX: 17 Base SYSTEM-WARNING-tmnxStateChange-2009 [CHASSIS]: ...` gives
  `SYSTEM-WARNING-tmnxStateChange-2009`.
- **Severity:** critical 2, major 3, minor 4, warning 4, cleared 6, indeterminate 6. Nokia's own syslog PRI
  mapping differs: critical 1, major 2, minor 3.
- **Filtering and gaps:**
  - 28 events whose Cause, Effect and Recovery are all "N/A" are omitted.
  - 706 records have no action.
  - 7210 SAS and SR Linux are not covered. SR Linux lines carry no stable event token.
  - If an operator changes a severity with `log event-control`, the token changes and will not match.
  - The words `CLEARED`, `INDETERMINATE` and `CRITICAL` in tokens are assumed by analogy; no example shows them.

## NVIDIA

**Records: 251**: Cumulus `switchd` 121, Cumulus FRR error categories 128, GPU `Xid` 1, NVSwitch `SXid` 1.

| Document | Version | URL | Retrieved |
|---|---|---|---|
| switchd Log Message Reference | Cumulus Linux 5.18 | https://docs.nvidia.com/networking-ethernet-software/cumulus-linux-518/Monitoring-and-Troubleshooting/switchd-Log-Message-Reference/ | 2026-11-20 |
| FRRouting Log Message Reference | Cumulus Linux 5.18 | https://docs.nvidia.com/networking-ethernet-software/cumulus-linux-518/Monitoring-and-Troubleshooting/FRRouting-Log-Message-Reference/ | 2026-11-20 |
| Xid Errors (Working with / Analyzing Xid Errors) | latest | https://docs.nvidia.com/deploy/xid-errors/latest/working-with-xid-errors.html | 2026-11-20 |
| Fabric Manager User Guide, NVSwitch SXid errors | — | https://docs.nvidia.com/datacenter/tesla/fabric-manager-user-guide/index.html#nvswitch-sxid-errors | 2026-11-20 |

- **Identifiers:**
  - switchd: the C symbol in the message text. In `switchd[1234]: <file>.c:<line> _range_limits_get: ...`, it is
    `_range_limits_get`.
  - FRR: the `[EC n]` number. `bgpd[1559]: [EC 33554454] ...` gives `33554454`.
  - GPU and NVSwitch: the family tokens `Xid` and `SXid`.
- **Severity:**
  - switchd: CRITICAL 2, ERROR 3, WARNING 4. Merged records use the most severe level.
  - FRR: 3, because these are logged through `flog_err` at LOG_ERR.
  - Xid/SXid: `null`, because the docs state no syslog priority.
- **Filtering and gaps:**
  - 677 of 835 distinct switchd messages have no distinctive token and are omitted. 19 records merge messages
    that share a token. `vlan_range_confi`, which looks truncated, was dropped.
  - FRR records give the category title, not the exact log text.
  - There are no per-code Xid/SXid records, because bare numbers would collide.
  - Onyx, UFM, NVSM/DGX, SONiC, NVOS and BlueField are excluded: there is no stable token in syslog or no public
    reference.
  - `sx_api_*` symbols may also appear in other software built on the Spectrum SDK.

## Palo Alto Networks

**Records: 364** (PAN-OS / Panorama System log).

| Document | URL | Retrieved |
|---|---|---|
| Syslog Severity Reference (System Log Reference), with the critical/high/medium/low/informational pages | https://docs.paloaltonetworks.com/ngfw/administration/monitoring/use-syslog-for-monitoring/system-log-reference | 2026-11-21 |
| System Log Fields | https://docs.paloaltonetworks.com/ngfw/administration/monitoring/use-syslog-for-monitoring/syslog-field-descriptions/system-log-fields | 2026-11-21 |
| Master Key help page (PAN-OS 11.1); source of the `mkey-*` actions | https://docs.paloaltonetworks.com/ngfw/help/11-1/device/device-master-key-and-diagnostics | 2026-11-21 |
| Knowledge Base articles kA1Ki000000TO8lKAG, kA14u0000004O9QCAU, kA14u000000g1HpCAI, kA10g000000CloXCAS, kA10g000000PNcBCAW, kA14u000000CqYPCA0, kA1Ki000000TNN1KAO, kA10g000000PMK1CAO, kA14u000000wkqJCAQ | linked per record | 2026-11-21 |

- The reference pages describe current PAN-OS (10.1–12.2 selectable).
- **Identifier:** the Event ID (CSV field 9). Commas end tokens.
  `... SYSTEM,vpn,0,2026/11/20 17:00:01,,tunnel-status-down,IPSEC-Tunnel-Primary,...` gives `tunnel-status-down`.
- **Severity:** the severity page maps as critical 2, high 3, medium 4, low 5, informational 6. Severity is
  `null` when an ID is documented at more than one severity.
- **Filtering and gaps:**
  - The source had 398 records. The build excludes the 34 generic identifiers listed under [Build](#build),
    including some KB-backed ones such as `link-change`, `state-change` and `fan-failure`.
  - Excluded during extraction:
    - generic one-word IDs (`general`, `auth`, `api`, `cli`, `gnmi`, `connect`, `initiate`, `terminate`,
      `restart`, `sync`, `N/A`);
    - IDs that contain spaces;
    - IDs whose message is only placeholders;
    - two `user-password-change-*` IDs whose documented text does not match the ID.
  - Explanations are generated from the documented message, subtype and severity. About 15 are hand-written
    from KB/doc material.
  - Recommended actions exist only where a KB or doc page gives steps, which is 12 records in the source.
  - The vendor tables are partly inconsistent. HA coverage is thin: only KB-evidenced IDs are included, and
    `ha-link-down` was not found. GlobalProtect `eventid` values are not documented.
