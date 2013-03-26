<?php

// /etc/crontab:
// 12 * * * * root <path>/cronjob.php

// when invoked via browser, do nothing
if(!defined('STDIN') && !defined($argc)) {
	die();
}

chdir(dirname(__FILE__));

require_once('config.php');

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

$result = $mysqli->query('SELECT COUNT(*) ips FROM ip_info');
$row = $result->fetch_assoc();
$rows = $row['ips'];
$result->close();

$result = $mysqli->query('SELECT id, ip, continent_code, country_code, country_code3, country_name, region, city, postal_code, latitude, longitude, dma_code, area_code FROM ip_info');
$stmt = $mysqli->prepare('UPDATE ip_info SET continent_code = ?, country_code = ?, country_code3 = ?, country_name = ?, region = ?, city = ?, postal_code = ?, latitude = ?, longitude = ?, dma_code = ?, area_code = ? WHERE id = ?');
$counter = 0;
$start_time = time();
while($row = $result->fetch_assoc()) {
	$counter++;
	if($counter % 100 == 0) {
		$elapsed = time()-$start_time;
		$end = ($elapsed*$rows/$counter)+$start_time;
		$end_time = date('Y-m-d H:i', $end);
		echo "$counter/$rows rows processed... (ETA: $end_time)\n";
	}

	$fail = false;
	$data = @geoip_record_by_name($row['ip']) or $fail = true;
	if($fail) {
		continue;
	}

	$different = false;
	$fields = array('continent_code', 'country_code', 'country_code3', 'country_name', 'region', 'city', 'postal_code', 'latitude', 'longitude', 'dma_code', 'area_code');
	foreach($fields as $field) {
		if("" . $data[$field] != "" . $row[$field]) {
			$different = true;
			break;
		}
	}

	if(!$different) {
		continue;
	}

	$stmt->bind_param('sssssssssssi', $data['continent_code'], $data['country_code'], $data['country_code3'], $data['country_name'], $data['region'], $data['city'], $data['postal_code'], $data['latitude'], $data['longitude'], $data['dma_code'], $data['area_code'], $row['id']);
	$stmt->execute();
}
$stmt->close();
$result->close();
$mysqli->close();

