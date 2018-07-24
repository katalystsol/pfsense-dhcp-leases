<?php
/*
 * status_dhcp_leases_json.php
 *
 * Implementation of the status_dhcp_leases.php to output in json format
 * Copyright (c) 2018 Katalyst Solutions, LLC
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2004-2018 Rubicon Communications, LLC (Netgate)
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

##|+PRIV
##|*IDENT=page-status-dhcpleases
##|*NAME=Status: DHCP leases
##|*DESCR=Allow access to the 'Status: DHCP leases' page.
##|*MATCH=status_dhcp_leases.php*
##|-PRIV

// TODO Temporary login procedure. Need to use external config to set this key / token
$key = 'myPfSense';
if ($_GET['key'] != $key) {
    die("Invalid Request");
}

//require_once("guiconfig.inc"); // This contains auth bit that we do not want to use along with other unneeded gui functions...
require_once("config.inc");

$pgtitle = array(gettext("Status"), gettext("DHCP Leases"));
$shortcut_section = "dhcp";

$leasesfile = "{$g['dhcpd_chroot_path']}/var/db/dhcpd.leases";

// Load MAC-Manufacturer table
//$mac_man = load_mac_manufacturer_table();

//include("head.inc");

function leasecmp($a, $b) {
    return strcmp($a[$_REQUEST['order']], $b[$_REQUEST['order']]);
}

function adjust_gmt($dt) {
    global $config;
    $dhcpd = $config['dhcpd'];
    foreach ($dhcpd as $dhcpditem) {
        $dhcpleaseinlocaltime = $dhcpditem['dhcpleaseinlocaltime'];
        if ($dhcpleaseinlocaltime == "yes") {
            break;
        }
    }
    if ($dhcpleaseinlocaltime == "yes") {
        $ts = strtotime($dt . " GMT");
        if ($ts !== false) {
            return strftime("%Y/%m/%d %H:%M:%S", $ts);
        }
    }
    /* If we did not need to convert to local time or the conversion failed, just return the input. */
    return $dt;
}

function remove_duplicate($array, $field) {
    foreach ($array as $sub) {
        $cmp[] = $sub[$field];
    }
    $unique = array_unique(array_reverse($cmp, true));
    foreach ($unique as $k => $rien) {
        $new[] = $array[$k];
    }
    return $new;
}

$awk = "/usr/bin/awk";
/* this pattern sticks comments into a single array item */
$cleanpattern = "'{ gsub(\"#.*\", \"\");} { gsub(\";\", \"\"); print;}'";
/* We then split the leases file by } */
$splitpattern = "'BEGIN { RS=\"}\";} {for (i=1; i<=NF; i++) printf \"%s \", \$i; printf \"}\\n\";}'";

/* stuff the leases file in a proper format into a array by line */
exec("/bin/cat {$leasesfile} | {$awk} {$cleanpattern} | {$awk} {$splitpattern}", $leases_content);
$leases_count = count($leases_content);
exec("/usr/sbin/arp -an", $rawdata);
$arpdata_ip = array();
$arpdata_mac = array();
foreach ($rawdata as $line) {
    $elements = explode(' ', $line);
    if ($elements[3] != "(incomplete)") {
        $arpent = array();
        $arpdata_ip[] = trim(str_replace(array('(', ')'), '', $elements[1]));
        $arpdata_mac[] = strtolower(trim($elements[3]));
    }
}
unset($rawdata);
$pools = array();
$leases = array();
$i = 0;
$l = 0;
$p = 0;

// Translate these once so we don't do it over and over in the loops below.
$online_string = gettext("online");
$offline_string = gettext("offline");
$active_string = gettext("active");
$expired_string = gettext("expired");
$reserved_string = gettext("reserved");
$dynamic_string = gettext("dynamic");
$static_string = gettext("static");

// Put everything together again
foreach ($leases_content as $lease) {
    /* split the line by space */
    $data = explode(" ", $lease);
    /* walk the fields */
    $f = 0;
    $fcount = count($data);
    /* with less than 20 fields there is nothing useful */
    if ($fcount < 20) {
        $i++;
        continue;
    }
    while ($f < $fcount) {
        switch ($data[$f]) {
            case "failover":
                $pools[$p]['name'] = trim($data[$f+2], '"');
                $pools[$p]['name'] = "{$pools[$p]['name']} (" . convert_friendly_interface_to_friendly_descr(substr($pools[$p]['name'], 5)) . ")";
                $pools[$p]['mystate'] = $data[$f+7];
                $pools[$p]['peerstate'] = $data[$f+14];
                $pools[$p]['mydate'] = $data[$f+10];
                $pools[$p]['mydate'] .= " " . $data[$f+11];
                $pools[$p]['peerdate'] = $data[$f+17];
                $pools[$p]['peerdate'] .= " " . $data[$f+18];
                $p++;
                $i++;
                continue 3;
            case "lease":
                $leases[$l]['ip'] = $data[$f+1];
                $leases[$l]['type'] = $dynamic_string;
                $f = $f+2;
                break;
            case "starts":
                $leases[$l]['start'] = $data[$f+2];
                $leases[$l]['start'] .= " " . $data[$f+3];
                $f = $f+3;
                break;
            case "ends":
                if ($data[$f+1] == "never") {
                    // Quote from dhcpd.leases(5) man page:
                    // If a lease will never expire, date is never instead of an actual date.
                    $leases[$l]['end'] = gettext("Never");
                    $f = $f+1;
                } else {
                    $leases[$l]['end'] = $data[$f+2];
                    $leases[$l]['end'] .= " " . $data[$f+3];
                    $f = $f+3;
                }
                break;
            case "tstp":
                $f = $f+3;
                break;
            case "tsfp":
                $f = $f+3;
                break;
            case "atsfp":
                $f = $f+3;
                break;
            case "cltt":
                $f = $f+3;
                break;
            case "binding":
                switch ($data[$f+2]) {
                    case "active":
                        $leases[$l]['act'] = $active_string;
                        break;
                    case "free":
                        $leases[$l]['act'] = $expired_string;
                        $leases[$l]['online'] = $offline_string;
                        break;
                    case "backup":
                        $leases[$l]['act'] = $reserved_string;
                        $leases[$l]['online'] = $offline_string;
                        break;
                }
                $f = $f+1;
                break;
            case "next":
                /* skip the next binding statement */
                $f = $f+3;
                break;
            case "rewind":
                /* skip the rewind binding statement */
                $f = $f+3;
                break;
            case "hardware":
                $leases[$l]['mac'] = $data[$f+2];
                /* check if it's online and the lease is active */
                if (in_array($leases[$l]['ip'], $arpdata_ip)) {
                    $leases[$l]['online'] = $online_string;
                } else {
                    $leases[$l]['online'] = $offline_string;
                }
                $f = $f+2;
                break;
            case "client-hostname":
                if ($data[$f+1] <> "") {
                    $leases[$l]['hostname'] = preg_replace('/"/', '', $data[$f+1]);
                } else {
                    $hostname = gethostbyaddr($leases[$l]['ip']);
                    if ($hostname <> "") {
                        $leases[$l]['hostname'] = $hostname;
                    }
                }
                $f = $f+1;
                break;
            case "uid":
                $f = $f+1;
                break;
        }
        $f++;
    }
    $l++;
    $i++;
    /* slowly chisel away at the source array */
    array_shift($leases_content);
}
/* remove the old array */
unset($lease_content);

/* remove duplicate items by mac address */
if (count($leases) > 0) {
    $leases = remove_duplicate($leases, "ip");
}

if (count($pools) > 0) {
    $pools = remove_duplicate($pools, "name");
    asort($pools);
}

$got_cid = false;

foreach ($config['interfaces'] as $ifname => $ifarr) {
    if (is_array($config['dhcpd'][$ifname]) &&
        is_array($config['dhcpd'][$ifname]['staticmap'])) {
        foreach ($config['dhcpd'][$ifname]['staticmap'] as $idx => $static) {
            if (!empty($static['mac']) || !empty($static['cid'])) {
                $slease = array();
                $slease['ip'] = $static['ipaddr'];
                $slease['type'] = $static_string;
                if (!empty($static['cid'])) {
                    $slease['cid'] = $static['cid'];
                    $got_cid = true;
                }
                $slease['mac'] = $static['mac'];
                $slease['if'] = $ifname;
                $slease['start'] = "";
                $slease['end'] = "";
                $slease['hostname'] = $static['hostname'];
                $slease['descr'] = $static['descr'];
                $slease['act'] = $static_string;
                $slease['online'] = in_array(strtolower($slease['mac']), $arpdata_mac) ? $online_string : $offline_string;
                $slease['staticmap_array_index'] = $idx;
                $leases[] = $slease;
            }
        }
    }
}

if ($_REQUEST['order']) {
    usort($leases, "leasecmp");
}

header('Content-Type: application/json');
echo json_encode($leases);
