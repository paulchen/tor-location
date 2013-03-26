<?php

// /etc/crontab:
// 12 * * * * root <path>/cronjob.php

// when invoked via browser, do nothing
if(!defined('STDIN') && !defined($argc)) {
	die();
}

require_once('config.php');

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

function incoming_connection_ips() {
	global $tor_ip, $tor_port, $process_name;

	$output = array();
	exec("netstat -tpen|grep '/$process_name'", $output);
	$ips = array();
	foreach($output as $value) {
		$value = preg_replace('/ +/', ' ', $value);
		$parts1 = explode(' ', $value);
		$parts2 = explode(':', $parts1[3]);
		$parts3 = explode(':', $parts1[4]);
		if($parts2[0] = $tor_ip && $parts2[1] == $tor_port) {
			$ips[] = $parts3[0];
		}
	}

	return array_unique($ips);
}

function current_tor_nodes() {
	global $mysqli, $process_name;

	if($process_name != 'tor') {
		return array();
	}

	$result = $mysqli->query("SELECT ActiveNetworkStatusTable, ActiveDescriptorTable FROM Status WHERE ID = '1'");
	$row = $result->fetch_assoc();
	$result->close();
	$status_table = $row['ActiveNetworkStatusTable'];
	$descriptor_table = $row['ActiveDescriptorTable'];

	$result = $mysqli->query("SELECT IP FROM $status_table");
	$ips = array();
	while($row = $result->fetch_assoc()) {
		$ips[] = $row['IP'];
	}
	$result->close();

	return array_unique($ips);
}

$ips1 = incoming_connection_ips();
$ips2 = current_tor_nodes();
$ips3 = array();
foreach($ips1 as $ip) {
	if(!in_array($ip, $ips2)) {
		$ips3[] = $ip;
	}
}

$query_start = "INSERT INTO ip_seen (ip, `timestamp`, ip_long) VALUES ";
$values = array();
foreach($ips3 as $ip) {
	$ip_long = ip2long($ip);
	$values[] = "('" . $mysqli->real_escape_string($ip) . "', NOW(), '" . $mysqli->real_escape_string($ip_long) . "')";

	if(count($values) == 1000) {
		$query = $query_start . implode(', ', $values);
		$mysqli->query($query);
		$values = array();
	}
}
if(count($values) > 0) {
	$query = $query_start . implode(', ', $values);
	$mysqli->query($query);
}

$result = $mysqli->query("SELECT DISTINCT ip FROM ip_seen WHERE ip NOT IN (SELECT ip FROM ip_info)");
$query_start = "INSERT INTO ip_info (ip, continent_code, country_code, country_code3, country_name, region, city, postal_code, latitude, longitude, dma_code, area_code, ip_long) VALUES ";
$values = array();
while($row = $result->fetch_assoc()) {
	$fail = false;
	$data = @geoip_record_by_name($row['ip']) or $fail = true;
	if($fail) {
		continue;
	}

	$ip_long = ip2long($row['ip']);
	$values[] = "('" . $mysqli->real_escape_string($row['ip']) .
		"', '" . $mysqli->real_escape_string($data['continent_code']) .
		"', '" . $mysqli->real_escape_string($data['country_code']) .
		"', '" . $mysqli->real_escape_string($data['country_code3']) .
		"', '" . $mysqli->real_escape_string($data['country_name']) .
		"', '" . $mysqli->real_escape_string($data['region']) .
		"', '" . $mysqli->real_escape_string($data['city']) .
		"', '" . $mysqli->real_escape_string($data['postal_code']) .
		"', '" . $mysqli->real_escape_string($data['latitude']) .
		"', '" . $mysqli->real_escape_string($data['longitude']) .
		"', '" . $mysqli->real_escape_string($data['dma_code']) .
		"', '" . $mysqli->real_escape_string($data['area_code']) .
		"', '" . $mysqli->real_escape_string($ip_long) .
		"')";

	if(count($values) == 100) {
		$query = $query_start . implode(', ', $values);
		$mysqli->query($query);
		$values = array();
	}
}
if(count($values) > 0) {
	$query = $query_start . implode(', ', $values);
	$mysqli->query($query);
}
$result->close();

$weekday = date('w')-1;
if($weekday < 0) {
	$weekday = 6;
}
$sunday = -$weekday+7;

$periods = array();
$periods[] = array(
			'table_country' => 'stats_day_country',
			'table_city' => 'stats_day_city',
			'start_date' => date('Y-m-d'),
			'end_date' => date('Y-m-d', strtotime("+1 day")),
		);
#if(date('H') == '23') {
	$periods[] = array(
				'table_country' => 'stats_week_country',
				'table_city' => 'stats_week_city',
				'start_date' => date('Y-m-d', strtotime("-$weekday days")),
				'end_date' => date('Y-m-d', strtotime("+$sunday days")),
			);
	$periods[] = array(
				'table_country' => 'stats_month_country',
				'table_city' => 'stats_month_city',
				'start_date' => date('Y-m-01'),
				'end_date' => date('Y-m-d', strtotime(date('Y-m-t'))+86400),
			);
	$periods[] = array(
				'table_country' => 'stats_year_country',
				'table_city' => 'stats_year_city',
				'start_date' => date('Y-01-01'),
				'end_date' => date('Y-m-d', strtotime(date('Y-12-31'))+86400),
			);
#}

foreach($periods as $period) {
	$table_country = $period['table_country'];
	$table_city = $period['table_city'];
	$start_date = $period['start_date'];
	$end_date = $period['end_date'];

	$mysqli->query("DELETE FROM $table_city WHERE date = '$start_date'");
	$query_start = "INSERT INTO $table_city (country, city, date, unique_ips, total_ips) VALUES ";
	$values = array();
	$result = $mysqli->query("SELECT country_name, city, COUNT(a.ip) total_ips, COUNT(DISTINCT a.ip) unique_ips
		FROM
		(SELECT ip, ip_long
			FROM ip_seen
			WHERE `timestamp` >= '$start_date'
				AND `timestamp` <= '$end_date'
				AND ip_long IN (SELECT ip_long FROM ip_info)) a
		JOIN ip_info i USING (ip_long)
                GROUP BY country_name, city");
	$country_unique_ips = array();
	$country_ips = array();
	while($row = $result->fetch_assoc()) {
		$values[] = "('" . $mysqli->real_escape_string($row['country_name']) .
			"', '" . $mysqli->real_escape_string($row['city']) .
			"', '" . $mysqli->real_escape_string($start_date) .
			"', '" . $mysqli->real_escape_string($row['unique_ips']) .
			"', '" . $mysqli->real_escape_string($row['total_ips']) .
			"')";
		if(count($values) == 1000) {
			$query = $query_start . implode(', ', $values);
			$mysqli->query($query);
			$values = array();
		}

		if(isset($country_unique_ips[$row['country_name']])) {
			$country_unique_ips[$row['country_name']] = $country_unique_ips[$row['country_name']] + $row['unique_ips'];
		}
		else {
			$country_unique_ips[$row['country_name']] = $row['unique_ips'];
		}

		if(isset($country_ips[$row['country_name']])) {
			$country_ips[$row['country_name']] = $country_ips[$row['country_name']] + $row['total_ips'];
		}
		else {
			$country_ips[$row['country_name']] = $row['total_ips'];
		}
	}
	$result->close();

	if(count($values) > 0) {
		$query = $query_start . implode(', ', $values);
		$mysqli->query($query);
	}

	$mysqli->query("DELETE FROM $table_country WHERE date = '$start_date'");
	$query_start = "INSERT INTO $table_country (country, date, unique_ips, total_ips) VALUES ";
	$values = array();
	foreach(array_keys($country_ips) as $country) {
		$values[] = "('" . $mysqli->real_escape_string($country) .
			"', '" . $mysqli->real_escape_string($start_date) .
			"', '" . $mysqli->real_escape_string($country_unique_ips[$country]) . 
			"', '" . $mysqli->real_escape_string($country_ips[$country]) .
			"')";
		if(count($values) == 1000) {
			$query = $query_start . implode(', ', $values);
			$mysqli->query($query);
			$values = array();
		}
	}

	if(count($values) > 0) {
		$query = $query_start . implode(', ', $values);
		$mysqli->query($query);
	}
}


$mysqli->close();

