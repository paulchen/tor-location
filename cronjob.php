<?php

// /etc/crontab:
// 12 * * * * root <path>/cronjob.php

// when invoked via browser, do nothing
if(!defined('STDIN') && !defined($argc)) {
	die();
}

require_once('common.php');

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

$query_start = 'INSERT INTO ip_seen (ip, "timestamp", ip_long) VALUES ';
$values = array();
$parameters = array();

foreach($ips3 as $ip) {
	$ip_long = ip2long($ip);
	$values[] = $ip;
	$values[] = $ip_long;
	$parameters[] = "(?, NOW(), ?)";

	if(count($parameters) == 1000) {
		$query = $query_start . implode(',', $parameters);
		db_query($query, $values);
		$values = array();
		$parameters = array();
	}
}
if(count($values) > 0) {
	$query = $query_start . implode(',', $parameters);
	db_query($query, $values);
}

$stmt = db_query_resultset('select distinct ip_seen.ip from ip_seen left join ip_info using (ip_long) where ip_info.ip is null');
$query_start = "INSERT INTO ip_info (ip, continent_code, country_code, country_code3, country_name, region, city, postal_code, latitude, longitude, dma_code, area_code, ip_long) VALUES ";
$values = array();
$parameters = array();
$fails = 0;
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
	$fail = false;
	$data = @geoip_record_by_name($row['ip']) or $fail = true;
	if($fail) {
		$fails++;
		continue;
	}

	$ip_long = ip2long($row['ip']);
	$values[] = $row['ip'];
	$values[] = $data['continent_code'];
	$values[] = $data['country_code'];
	$values[] = $data['country_code3'];
	$values[] = iconv('ISO-8859-1', 'UTF-8', $data['country_name']);
	$values[] = $data['region'];
	$values[] = iconv('ISO-8859-1', 'UTF-8', $data['city']);
	$values[] = $data['postal_code'];
	$values[] = $data['latitude'];
	$values[] = $data['longitude'];
	$values[] = $data['dma_code'];
	$values[] = $data['area_code'];
	$values[] = $ip_long;
	$parameters[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

	if(count($values) > 1000) {
		$query = $query_start . implode(',', $parameters);
		db_query($query, $values);
		$values = array();
		$parameters = array();
	}
}
if(count($values) > 0) {
	$query = $query_start . implode(',', $parameters);
	db_query($query, $values);
}
db_stmt_close($stmt);

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

	db_query("DELETE FROM $table_city WHERE date = ?", array($start_date));
	$query_start = "INSERT INTO $table_city (country, city, date, unique_ips, total_ips) VALUES ";
	$values = array();
	$parameters = array();
	$result = db_query('SELECT country_name, city, SUM(a.count) total_ips, COUNT(a.ip) unique_ips
		FROM
		(SELECT ip, ip_long, COUNT(*) count
			FROM ip_seen
			WHERE "timestamp" >= ?
				AND "timestamp" <= ?
			GROUP BY ip, ip_long) a
		JOIN ip_info i USING (ip_long)
                GROUP BY country_name, city', array($start_date, $end_date));
	$country_unique_ips = array();
	$country_ips = array();
	foreach($result as $row) {
		$values[] = $row['country_name'];
		$values[] = $row['city'];
		$values[] = $start_date;
		$values[] = $row['unique_ips'];
		$values[] = $row['total_ips'];
		$parameters[] = '(?, ?, ?, ?, ?)';

		if(count($parameters) == 1000) {
			$query = $query_start . implode(',', $parameters);
			db_query($query, $values);
			$values = array();
			$parameters = array();
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

	if(count($values) > 0) {
		$query = $query_start . implode(',', $parameters);
		db_query($query, $values);
	}

	db_query("DELETE FROM $table_country WHERE date = ?", array($start_date));
	$query_start = "INSERT INTO $table_country (country, date, unique_ips, total_ips) VALUES ";
	$values = array();
	$parameters = array();
	foreach(array_keys($country_ips) as $country) {
		$values[] = $country;
		$values[] = $start_date;
		$values[] = $country_unique_ips[$country];
		$values[] = $country_ips[$country];
		$parameters[] = '(?, ?, ?, ?)';

		if(count($parameters) == 1000) {
			$query = $query_start . implode(',', $parameters);
			db_query($query, $values);
			$values = array();
			$parameters = array();
		}
	}

	if(count($values) > 0) {
		$query = $query_start . implode(',', $parameters);
		db_query($query, $values);
	}
}

$mysqli->close();

touch($status_file);

