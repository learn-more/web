<?php
/*
 * PROJECT:     ReactOS Testman
 * LICENSE:     GPL-2.0+ (https://spdx.org/licenses/GPL-2.0+)
 * PURPOSE:     Result Details Page
 * COPYRIGHT:   Copyright 2008-2018 Colin Finck (colin@reactos.org)
 *              Copyright 2012-2014 Kamil Hornicek (kamil.hornicek@reactos.org)
 */

	require_once("config.inc.php");
	require_once(ROOT_PATH . "../www.reactos.org_config/testman-connect.php");
	require_once("utils.inc.php");
	require_once("languages.inc.php");
	require_once("autoload.inc.php");
	require_once(ROOT_PATH . "rosweb/exceptions.php");
	require_once(ROOT_PATH . "rosweb/rosweb.php");
	require_once(ROOT_PATH . "rosweb/gitinfo.php");

	//$rw = new RosWeb($supported_languages);
	$rw = new RosWeb();
	$lang = $rw->getLanguage();
	require_once(ROOT_PATH . "rosweb/lang/$lang.inc.php");
	require_once("lang/$lang.inc.php");

	$gi = new GitInfo();

	try
	{
		// Check the parameters.
		if (!array_key_exists("id", $_GET))
			throw new ErrorMessageException("Necessary information not specified");

		$id = $_GET["id"];

		// Connect to the database.
		$dbh = new PDO("mysql:host=" . TESTMAN_DB_HOST . ";dbname=" . TESTMAN_DB_NAME, TESTMAN_DB_USER, TESTMAN_DB_PASS);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Get information about this result.
		$stmt = $dbh->prepare(
			"SELECT UNCOMPRESS(l.log) AS log, e.status, e.count, e.failures, e.skipped, e.todo, e.time, s.module, s.test, UNIX_TIMESTAMP(r.timestamp) AS timestamp, r.revision, r.platform, src.name, r.comment, e.suite_id " .
			"FROM winetest_results e " .
			"JOIN winetest_logs l ON e.id = l.id " .
			"JOIN winetest_suites s ON e.suite_id = s.id " .
			"JOIN winetest_runs r ON e.test_id = r.id " .
			"JOIN sources src ON r.source_id = src.id " .
			"WHERE e.id = :id"
		);
		$stmt->bindParam(":id", $id);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		// Post-process the log for convenience.
		$module_urls = array();
		$search_urls = array("modules/rostests/winetests", "modules/rostests/apitests");

		$pattern_core = "#^([a-z]*:?\()([a-zA-Z0-9\/_]+.[a-z]+):([0-9]+)(\))#m";
		$pattern_test = "#^([a-zA-Z0-9_]+.[a-z]+):([0-9]+)(: )#m";

		$replacement_core = '$1<a href="' . VIEWVC_TRUNK . ';hb=' . $row["revision"] . ';f=$2#l$3">$2:$3</a>$4';

		$log = preg_replace($pattern_core, $replacement_core, htmlspecialchars($row["log"]));
		$log = preg_replace_callback($pattern_test, "file_callback", $log);

		$reader = new WineTest_Reader();
		$history = $reader->getTestHistory($row['suite_id'], $row['platform']);
	}
	catch (ErrorMessageException $e)
	{
		die($e->getMessage());
	}
	catch (Exception $e)
	{
		die($e->getFile() . ":" . $e->getLine() . " - " . $e->getMessage());
	}

	// Functions
	function file_callback($matches)
	{
		global $row, $module_urls;

		if (!isset($module_urls[$row["module"] . $matches[1]]))
		{
			$url_chunk = get_file_url($row["module"], $matches[1]);
			if (!$url_chunk)
				return $matches[0];

			$module_urls[$row["module"] . $matches[1]] = $url_chunk;
		}

		return '<a href="' . VIEWVC_TRUNK . ';hb=' . $row["revision"] . ';f=' . $module_urls[$row["module"].$matches[1]] . $matches[1] . '#l' . $matches[2] . '">' . $matches[1] . ':' . $matches[2] . '</a>' . $matches[3];
	}

	function get_file_url($module, $file)
	{
		global $search_urls;

		foreach ($search_urls as $surl)
		{
			$http_header = @get_headers(VIEWVC_TRUNK . ";f=$surl/$module/$file");
			if ($http_header[0] == 'HTTP/1.1 404 Not Found')
				continue;

			return "$surl/$module/";
		}
	}
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?php echo $testman_langres["detail_title"]; ?></title>
	<?php $rw->printHead(); ?>
	<link rel="stylesheet" type="text/css" href="css/detail.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js@2.8.0"></script>
	<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@0.7.0"></script>
</head>
<body>

<h2><?php echo $testman_langres["detail_title"]; ?></h2>

<table class="table table-bordered table-striped table-hover">
	<thead>
		<tr>
			<th colspan="2"><?php echo $testman_langres["thisresult"]; ?></th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td colspan="2">
				<div class="col"><strong><?php echo $testman_langres["testsuite"]; ?>:</strong> <?php echo $row["module"].':'.$row["test"];?></div>
				<div class="col"><strong><?php echo $testman_langres["totaltests"]; ?>:</strong> <?php echo GetTotalTestsString($row); ?></div>
				<div class="col"><strong><?php echo $testman_langres["failedtests"]; ?>:</strong> <?php echo $row["failures"]; ?></div>
				<div class="col"><strong><?php echo $testman_langres["skippedtests"]; ?>:</strong> <?php echo $row["skipped"]; ?></div>
				<div class="col"><strong><?php echo $testman_langres["todotests"]; ?>:</strong> <?php echo $row["todo"]; ?></div>
				<div class="col"><strong><?php echo $testman_langres["timetest"]; ?>:</strong> <?php echo $row["time"]; ?>s</div>
			</td>
		</tr>
		<tr>
			<td colspan="2">
				<canvas id="myChart" height="200px"></canvas>
				<script>
				<?php
					$succeeded_tests = array();
					$failed_tests = array();
					$test_times = array();
					$revision_list = array();
					foreach ($history as $item)
					{
						$count = (int)$item['count'];
						$skipped = (int)$item['skipped'];
						$failed = (int)$item['failures'];
						$succeeded_tests[] = ($count - $failed);
						$failed_tests[] = $failed;
						$test_times[] = $item['time'];
						$revision_list[] = $gi->getShortHash($item['revision']);
					}
				?>
				var ctx = document.getElementById('myChart').getContext('2d');
				var chart = new Chart(ctx,
				{
					type: 'bar',
					data: {
						datasets: [{
							label: 'Failed',
							data: <?php echo json_encode($failed_tests); ?>,
							backgroundColor: "rgba(255, 99, 132, 0.2)",
							borderColor: "rgb(255, 99, 132)",
							borderWidth: 1,
							barPercentage: 1,
							categoryPercentage: 1,
							yAxisID: 'bar-y-axis',
							order: 2
						}, {
							label: 'Succeeded',
							data: <?php echo json_encode($succeeded_tests); ?>,
							backgroundColor: "rgba(54, 162, 235, 0.2)",
							borderColor: "rgb(54, 162, 235)",
							borderWidth: 1,
							barPercentage: 1,
							categoryPercentage: 1,
							yAxisID: 'bar-y-axis',
							order: 1
						}, {
							label: 'Test time',
							data: <?php echo json_encode($test_times); ?>,
							order: 3,
							borderColor: "rgba(153, 102, 255, 1)",
							yAxisID: 'line-y-axis',
							type: 'line',
							fill: false,
							lineTension: 0,
							datalabels: {
								offset: '10',
								align: 'right',
								backgroundColor: 'white',
								formatter: function(value, ctx) {
									if (ctx.dataIndex == 0) {
										return value;
									} else {
										let prev = ctx.dataset.data[ctx.dataIndex - 1];
										if (prev < value) {
											return "+" + (value-prev).toFixed(1);
										} else if (prev > value) {
											return "-" + (prev-value).toFixed(1);
										} else {
											return ".";
										}
									}
								},
							}
						}],
						labels: <?php echo json_encode($revision_list); ?>
					},

					options: {
						maintainAspectRatio: false,
						plugins: {
							datalabels: {
								align: 'end',
								color: function(ctx) {
									return ctx.dataset.borderColor;
								},
								formatter: function(value, ctx) {
									if (ctx.dataIndex == 0) {
										return value;
									} else {
										let prev = ctx.dataset.data[ctx.dataIndex - 1];
										if (prev < value) {
											return "+" + (value-prev);
										} else if (prev > value) {
											return "-" + (prev-value);
										} else {
											return ".";
										}
									}
								},
							}
						},
						elements: {
							line: {
								tension: 0	// disable bezier curves
							}
						},
						scales: {
							yAxes: [{
								id: 'bar-y-axis',
								type: 'linear',
								showLine: true,
								stacked: true,
								ticks: {
									beginAtZero: true,
								},
							}, {
								id: 'line-y-axis',
								type: 'linear',
								ticks: {
									beginAtZero: false,
									fontColor: "rgba(153, 102, 255, 1)"
								},
							}],
							xAxes: [
								{ stacked: true }
							]
						}
						/*animation: {
							duration: 0 // general animation time
						},
						hover: {
							animationDuration: 0 // duration of animations when hovering an item
						},
						responsiveAnimationDuration: 0 // animation duration after a resize*/
					}
				});
				</script>
			</td>
		</tr>
		<?php
			if (array_key_exists("prev", $_GET) && $_GET['prev'] != 0)
			{
				echo '<tr>';
				echo '<td>' . $testman_langres["show_diff"] . '</td>';

				echo '<td>';
				echo '<a class="btn btn-default" href="diff.php?id1=' . $_GET['prev'] . '&id2=' . $_GET['id'] . '&type=1&strip=0">' . $testman_langres["diff_sbs"] . '</a> ';
				echo '<a class="btn btn-default" href="diff.php?id1=' . $_GET['prev'] . '&id2=' . $_GET['id'] . '&type=1&strip=1">' . $testman_langres["diff_sbs_stripped"] . '</a> ';
				echo '<a class="btn btn-default" href="diff.php?id1=' . $_GET['prev'] . '&id2=' . $_GET['id'] . '&type=2&strip=1">' . $testman_langres["diff_inline_stripped"] . '</a>';
				echo '</td>';

				echo '</tr>';
			}
		?>
		<tr>
			<td><?php echo $testman_langres["log"]; ?>:</td>
			<td><pre><?php echo $log; ?></pre></td>
		</tr>
	</tbody>
</table><br>

<table class="table table-bordered table-striped table-hover">
	<thead>
		<tr>
			<th colspan="2"><?php echo $testman_langres["associatedtest"]; ?></th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td><?php echo $shared_langres["revision"]; ?>:</td>
			<td><?php echo $row["revision"]; ?></td>
		</tr>
		<tr>
			<td><?php echo $testman_langres["date"]; ?>:</td>
			<td><?php echo GetDateString($row["timestamp"]); ?></td>
		</tr>
		<tr>
			<td><?php echo $testman_langres["source"]; ?>:</td>
			<td><?php echo $row["name"]; ?></td>
		</tr>
		<tr>
			<td><?php echo $testman_langres["platform"]; ?>:</td>
			<td><?php echo GetPlatformString($row["platform"]); ?></td>
		</tr>
		<tr>
			<td><?php echo $testman_langres["comment"]; ?>:</td>
			<td><?php echo $row["comment"]; ?></td>
		</tr>
	</tbody>
</table>

</body>
</html>
