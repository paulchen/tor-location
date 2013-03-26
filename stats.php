<?php

require_once('config.php');

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

$period = 'day';
if(isset($_REQUEST['period'])) { 
	$period = $_REQUEST['period'];
}
$what = 'country';
if(isset($_REQUEST['what'])) {
	$what = $_REQUEST['what'];
}
$date = date('Y-m-d');
if(isset($_REQUEST['date'])) {
	$date = $mysqli->real_escape_string($_REQUEST['date']);
}

if($what != 'country' && $what != 'city') {
	die();
}

if($period != 'day' && $period != 'week' && $period != 'month' && $period != 'year') {
	die();
}

$table_name = 'stats_' . $period . '_' . $what;

$sort = 'unique';
$sql_sort = 'unique_ips DESC, total_ips DESC';
if(isset($_REQUEST['sort']) && $_REQUEST['sort'] == 'total') {
	$sort = 'total';
	$sql_sort = 'total_ips DESC, unique_ips DESC';
}

$country = '';
if(isset($_REQUEST['country'])) {
	$country = $mysqli->real_escape_string(urldecode($_REQUEST['country']));
}

if($what == 'country') {
	$headers = array('Country', 'Unique IPs', 'Total IPs');
	$result = $mysqli->query("SELECT country, unique_ips, total_ips FROM $table_name WHERE date = '$date' ORDER BY $sql_sort, country ASC");
}
else {
	$headers = array('Country', 'City', 'Unique IPs', 'Total IPs');
	if($country != '') {
		$result = $mysqli->query("SELECT country, city, unique_ips, total_ips FROM $table_name WHERE date = '$date' AND country = '$country' ORDER BY $sql_sort, city ASC");
	}
	else {
		$result = $mysqli->query("SELECT country, city, unique_ips, total_ips FROM $table_name WHERE date = '$date' ORDER BY $sql_sort, country ASC, city ASC");
	}
}

$rows = array();
$total = 0;
$total_unique = 0;
while($line = $result->fetch_assoc()) {
	$row = array();
	foreach($line as $key => $value) {
		$row[] = $value;
		if($key == 'unique_ips') {
			$total_unique += $value;
		}
		else if($key == 'total_ips') {
			$total += $value;
		}
	}
	$rows[] = $row;
}
$result->close();

$previous_period = date_sub(new DateTime($date), date_interval_create_from_date_string("1 $period"))->format('Y-m-d');
$next_period = date_add(new DateTime($date), date_interval_create_from_date_string("1 $period"))->format('Y-m-d');

$countries = array();
$result = $mysqli->query("SELECT DISTINCT country FROM $table_name ORDER BY country ASC");
while($line = $result->fetch_assoc()) {
	$countries[] = $line['country'];
}
$result->close();

$periods = array('day', 'week', 'month', 'year');
$period_dates = array();
foreach($periods as $period_name) {
	$dates = array();
	$table_name = 'stats_' . $period_name . '_country';
	$result = $mysqli->query("SELECT DISTINCT date FROM $table_name ORDER BY date DESC");
	while($line = $result->fetch_assoc()) {
		$dates[] = $line['date'];
	}
	$result->close();
	$period_dates[$period_name] = $dates;
}

$result = $mysqli->query("SELECT UNIX_TIMESTAMP(MAX(`timestamp`)) last_updated FROM ip_seen");
$line = $result->fetch_assoc();
$last_updated = $line['last_updated'];
$result->close();

$mysqli->close();

echo '<?xml version="1.0" ?>';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN"
    "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="content-type" content="text/html; charset=UTF-8" />
	<title>Access statistics <?php echo $site_name ?></title>
	<style type="text/css">
	th, td { text-align: right; }
	th:first-child, td:first-child { text-align: left; }
	</style>
</head>
<body>

<h1>Access statistics <?php echo $site_name ?></h1>

<p>
	<a href="/"><?php echo $site_name ?></a>
</p>

<?php foreach($period_dates as $period_name => $dates): ?>
	<form method="get" action="<?php echo $_SERVER['PHP_SELF']; ?>">
		<div>
			Show statistics for <?php echo $period_name; ?> starting with:
			<select name="date">
				<?php foreach($dates as $single_date): ?>
					<?php if($period_name == $period && $single_date == $date): ?>
						<option value="<?php echo $single_date; ?>" selected="selected"><?php echo $single_date; ?></option>
					<?php else: ?>
						<option value="<?php echo $single_date; ?>"><?php echo $single_date; ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
			<input type="submit" value="Go" />
			<input type="hidden" name="period" value="<?php echo $period_name; ?>" />
			<input type="hidden" name="what" value="<?php echo $what; ?>" />
			<input type="hidden" name="sort" value="<?php echo $sort; ?>" />
			<?php if($country != ''): ?>
				<input type="hidden" name="country" value="<?php echo htmlentities($country, ENT_QUOTES, 'UTF-8'); ?>" />
			<?php endif; ?>
		</div>
	</form>
<?php endforeach; ?>

<hr />

<h2>Showing <?php echo $what; ?> statistics for <?php echo $period; ?> starting with <?php echo $date; ?></h2>
<div>
	<?php if($what == 'country' || $country == ''): ?>
		<a href="<?php echo $_SERVER['PHP_SELF'] ."?what=$what&amp;period=$period&amp;date=$previous_period&amp;sort=$sort"; ?>">Previous period</a>
		<a href="<?php echo $_SERVER['PHP_SELF'] ."?what=$what&amp;period=$period&amp;date=$next_period&amp;sort=$sort"; ?>">Next period</a>
	<?php else: ?>
		<a href="<?php echo $_SERVER['PHP_SELF'] ."?what=$what&amp;period=$period&amp;date=$previous_period&amp;sort=$sort&amp;country=" . urlencode($country); ?>">Previous period</a>
		<a href="<?php echo $_SERVER['PHP_SELF'] ."?what=$what&amp;period=$period&amp;date=$next_period&amp;sort=$sort&amp;country=" . urlencode($country); ?>">Next period</a>
	<?php endif; ?>
</div>
<div>
	<?php if($what == 'city'): ?>
		<a href="<?php echo $_SERVER['PHP_SELF'] . "?what=country&amp;period=$period&amp;date=$date&amp;sort=$sort"; ?>">Show country statistics</a>
	<?php else: ?>
		<a href="<?php echo $_SERVER['PHP_SELF'] . "?what=city&amp;period=$period&amp;date=$date&amp;sort=$sort"; ?>">Show city statistics</a>
	<?php endif; ?>
</div>
<div>
	<?php if($sort == 'unique'): ?>
		<?php if($what == 'country' || $country == ''): ?>
			<a href="<?php echo $_SERVER['PHP_SELF'] . "?what=$what&amp;period=$period&amp;date=$date&amp;sort=total"; ?>">Sort by &quot;Total IPs&quot;</a>
		<?php else: ?>
			<a href="<?php echo $_SERVER['PHP_SELF'] . "?what=$what&amp;period=$period&amp;date=$date&amp;country=" . urlencode($country) . "&amp;sort=total"; ?>">Sort by &quot;Total IPs&quot;</a>
		<?php endif; ?>
	<?php else: ?>
		<?php if($what == 'country' || $country == ''): ?>
			<a href="<?php echo $_SERVER['PHP_SELF'] . "?what=$what&amp;period=$period&amp;date=$date"; ?>">Sort by &quot;Unique IPs&quot;</a>
		<?php else: ?>
			<a href="<?php echo $_SERVER['PHP_SELF'] . "?what=$what&amp;period=$period&amp;date=$date&amp;country=" . urlencode($country); ?>">Sort by &quot;Unique IPs&quot;</a>
		<?php endif; ?>
	<?php endif; ?>
</div>

<?php if($what == 'city'): ?>
<div style="padding-top: 10px;">
	<form method="get" action="<?php echo $_SERVER['PHP_SELF']; ?>">
		<div>
		Country:
		<select name="country">
			<option value="">All</option>
			<?php foreach($countries as $country_name): ?>
				<?php if($country == $country_name): ?>
					<option value="<?php echo urlencode($country_name); ?>" selected="selected"><?php echo htmlentities($country_name); ?></option>
				<?php else: ?>
					<option value="<?php echo urlencode($country_name); ?>"><?php echo htmlentities($country_name); ?></option>
				<?php endif; ?>
			<?php endforeach; ?>
		</select>
		<input type="submit" value="Go" />
		<input type="hidden" name="period" value="<?php echo $period; ?>" />
		<input type="hidden" name="what" value="<?php echo $what; ?>" />
		<input type="hidden" name="sort" value="<?php echo $sort; ?>" />
		<input type="hidden" name="date" value="<?php echo $date; ?>" />
		<?php if($country != ''): ?>
			<a href="<?php echo $_SERVER['PHP_SELF'] . "?what=country&amp;period=$period&amp;sort=$sort&amp;date=$date"; ?>">All</a>
		<?php endif; ?>
	</form>
</div>
<?php endif; ?>

<table>
	<tr>
		<?php foreach($headers as $header): ?>
			<th><?php echo htmlentities($header); ?></th>
		<?php endforeach; ?>
	</tr>
	<?php foreach($rows as $row): ?>
		<tr>
			<?php foreach($row as $col => $cell): ?>
				<td>
					<?php if($col == 0): ?>
						<a href="<?php echo $_SERVER['PHP_SELF'] ."?what=city&amp;period=$period&amp;date=$date&amp;sort=$sort&amp;country=" . urlencode($cell); ?>">
					<?php endif; ?>
					<?php echo htmlentities($cell); ?>
					<?php if($col == 0): ?>
						</a>
					<?php endif; ?>
				</td>
			<?php endforeach; ?>
		</tr>
	<?php endforeach; ?>
	<tr>
		<th>Total</th>
		<?php if($what == 'city'): ?>
			<th></th>
		<?php endif; ?>
		<th><?php echo $total_unique; ?></th>
		<th><?php echo $total; ?></th>
	</tr>
</table>

<hr />

<?php if($process_name == 'tor'): ?>
<h2>How are these statistics generated?</h2>
<p>
	Every hour, a cronjob collects all source IP addresses of all connections to the <a href="https://www.torproject.org/">Tor</a> node at tor.rueckgr.at. IP addresses of known Tor relays and exit nodes according to <a href="/">torstatus.rueckgr.at</a> are ignored (however, since this list does not include bridges, they are not ignored). The resulting set of IP addresses is stored in a MySQL database.
</p>
<p>
	Furthermore, the cronjob counts the number of (unique) IP addresses that were seen over a certain period of time (one day, one week, one month or one year) and caches this information in the database so it can be obtained quickly when rendering this page. The value <strong>Unique IPs</strong> counts a single IP address only once, <strong>Total IPs</strong> counts a single IP address as often as it was seen (at most once per hour).
</p>

<hr />
<?php endif; ?>

<p>
	Last updated: <?php echo date('Y-m-d H:i P', $last_updated); ?>
</p>
<p>
	<a href="http://validator.w3.org/check?uri=referer"><img
        	src="valid-xhtml11.png"
	        alt="Valid XHTML 1.1" height="31" width="88" /></a>
</p>
<p>
	<a href="mailto:paulchen@rueckgr.at">Paul Staroch</a>
</p>

</body>
</html>

