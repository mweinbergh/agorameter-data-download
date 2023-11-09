<?php
/*
 * Download German electricity generation, demand, import and export data 
 * from the Agora Energiewende think tank website https://www.agora-energiewende.org
 */
class agorameterDownload {

private 
	$rangeChunks=[],
	$rangeChunksPointer=0,
	$recordsCount=[],
	$socks5Proxy='',
	$mapping=[],
	$journal=[],
	$apiUrl='https://api.agora-energy.org/api/raw-data',
	$curlConnectTimeout=10,
	$curlTimeout=30,
	$preFetchShellScript=null,
	$postFetchShellScript=null,
	$userAgent='';

public function __construct($firstDay, $lastDay, $nameFormat='sanitize') {
	$mapping=[
		'prod' => [
			'Biomass' => 'biomass',
			'Grid emission factor' => 'emission_intensity',
			'Hard Coal' => 'coal',
			'Hydro' => 'run_of_the_river',
			'Lignite' => 'lignite',
			'Natural Gas' => 'gas',
			'Nuclear' => 'uranium',
			'Other' => 'other',
			'Pumped storage generation' => 'hydro_pumped_storage',
			'Solar' => 'solar',
			'Total conventional power plant' => 'conventional_power',
			'Total electricity demand' => 'total_load',
			'Total grid emissions' => 'emission_co2',
			'Wind offshore' => 'wind_offshore',
			'Wind onshore' => 'wind_onshore',
		],
		'imex' => [
			'Power price' => 'power_price',
			'Net Total' => 'sum_import_export',
			'NO' => 'NO',
			'DK' => 'DK',
			'SE' => 'SE',
			'PL' => 'PL',
			'CZ' => 'CZ',
			'AT' => 'AT',
			'CH' => 'CH',
			'FR' => 'FR',
			'LU' => 'LU',
			'BE' => 'BE',
			'NL' => 'NL',
		]
	];
	if( $nameFormat=='sanitize' ) {
		// replace all non-alphanumeric characters with underscore
		foreach( $mapping as $type=>$dummy ) foreach( $mapping[$type] as $k=>$v ) $this->mapping[$type][$k] = preg_replace('/[^a-z0-9]+/', '_', strtolower($k));
	} elseif( $nameFormat=='legacy' ) {
		// use names from the legacy Agora chart
		foreach( $mapping as $type=>$dummy ) foreach( $mapping[$type] as $k=>$v ) $this->mapping[$type][$k] = $v;
	} else {
		// leave names unchanged
		foreach( $mapping as $type=>$dummy ) foreach( $mapping[$type] as $k=>$v ) $this->mapping[$type][$k] = $k;
	}
	$this->writeJournal('mapping', $this->mapping);
	$this->rangeChunks=$this->getMonthRanges($firstDay,$lastDay);
	$this->writeJournal('rangeChunks', $this->rangeChunks);
	$this->userAgent='curl PHP ' . phpversion();
}

public function getNextDataChunk($powerUnit=1E9) { // GW = 1E9, MW=1E6
	if( !isset($this->rangeChunks[$this->rangeChunksPointer]) ) return false;
	$firstDay = $this->rangeChunks[$this->rangeChunksPointer]['firstDay'];
	$lastDay = $this->rangeChunks[$this->rangeChunksPointer]['lastDay'];
	$this->writeJournal('getNextDataChunk', [ 'rangeChunksPointer'=>$this->rangeChunksPointer, 'firstDay'=>$firstDay, 'lastDay'=>$lastDay ]);
	$this->rangeChunksPointer++;
	$result=$this->fetchAgoraData($firstDay, $lastDay, $powerUnit);
	$this->writeJournal("recordCount", $this->recordsCount);
	return $result;
}

private function fetchAgoraData($firstDay, $lastDay, $powerUnit) {
	$result=$this->fetchAgoraDatasetByType($firstDay, $lastDay, [], 'prod');
	$result=$this->fetchAgoraDatasetByType($firstDay, $lastDay, $result, 'imex');
	if( !isset($result['error']) ) {
		$powerUnit = $powerUnit==1E9 ? null : 1E9/$powerUnit;
		$error='';
		foreach( $result as $t=>$d ) {
			if( !is_array($d) || count($d)!=count($this->mapping['prod'])+count($this->mapping['imex']) ) {
				$warning="The number of elements of the mapping and the received data do not match in Record '$t'.";
				$this->writeJournal('warning', ['warning'=>$warning, 'chunk'=>$this->getMonthChunksPointer() ] );
				break;
			}
			if( $powerUnit ) {
				$exclude=[ 'power_price'=>true, 'emission_intensity'=>true, 'emission_co2'=>true ];
				foreach($d as $k=>$v ) {
					if( !isset($exclude[$k]) ) {
						$result[$t][$k] = $v*$powerUnit;
					}
				}
			}
		}
		if( !$error ) {
			$this->recordsCount['chunks'][$this->rangeChunksPointer]=count($result);
			$total=0;
			foreach( $this->recordsCount['chunks'] as $r ) $total+=$r;
			$this->recordsCount['total']=$total;
		}
	}
	return $result;
}

private function fetchAgoraDatasetByType($firstDay, $lastDay, $result, $type) {
	$ch = curl_init();
	$valueNames = "'" . join("','", array_keys($this->mapping[$type])) . "'";
	$filters=[
		'prod'=>"{
			'filters':
			{
				'from':'$firstDay',
				'to':'$lastDay',
				'generation':[$valueNames]
			},
			'x_coordinate':'date_id',
			'y_coordinate':'value',
			'view_name':'live_gen_plus_emi_de_hourly',
			'kpi_name':'power_generation',
			'z_coordinate':'generation'
		}",
		'imex'=>"{
			'filters': {
				'from': '$firstDay',
				'to': '$lastDay',
				'legends': [
					'Power price',
					'Net Total',
					'NO',
					'DK',
					'SE',
					'PL',
					'CZ',
					'AT',
					'CH',
					'FR',
					'LU',
					'BE',
					'NL'
				]
			},
			'kpi_name': 'power_import_export',
			'view_name': 'live_exchange_plus_price_de_hourly',
			'x_coordinate': 'date_id',
			'y_coordinate': 'value',
			'z_coordinate': 'legends'
		}"
	];
	$filters[$type]=preg_replace("/[\t\r\n]+/", "", $filters[$type]);
	$filters[$type]=preg_replace("/'/", '"', $filters[$type]);
	$response = $this->curl_exec($ch, $filters[$type]);
	$data=json_decode($response, true);
	if( isset($data['data']['data']) && is_array($data['data']['data']) ) {
		foreach($data['data']['data'] as $d ) {
			$name = isset($this->mapping[$type][$d[2]]) ? $this->mapping[$type][$d[2]] : $d[2];
			$result[$d[0]][$name]=$d[1];
		}
	} else {
		$error="No valid data received.";
		$this->writeJournal('error', ['error'=>$error, 'type'=>$type, 'chunk'=>$this->getMonthChunksPointer() ]);
		$result['error']=$error;
	}
	$lastKey=array_key_last($result); if( count($result)>1 && strstr($lastKey, 'T00:00:00') ) unset($result[$lastKey]);
	return $result;
}

private function curl_exec($ch, $filters) {
	$api_key="agora_live_62ce76dd202927.67115829";
	$curlOptions=[
		CURLOPT_URL => $this->apiUrl,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CUSTOMREQUEST => "POST",
		CURLOPT_POSTFIELDS => $filters,
		CURLOPT_ENCODING => '',
		CURLINFO_HEADER_OUT => '',
		CURLOPT_HTTPHEADER => [
			"User-Agent: $this->userAgent",
			"Accept: */*",
			"Accept-Encoding: gzip, deflate, br",
			"Content-Type: application/json",
			"Api-key: $api_key",
			"Connection: keep-alive",
		],
		CURLOPT_CONNECTTIMEOUT => $this->curlConnectTimeout,
		CURLOPT_TIMEOUT => $this->curlTimeout,
	];
	if( $this->socks5Proxy ) {
		$curlOptions[CURLOPT_PROXYTYPE]=CURLPROXY_SOCKS5;
		$curlOptions[CURLOPT_PROXY]=$this->socks5Proxy;
	}
	curl_setopt_array($ch, $curlOptions);
	if( $this->preFetchShellScript ) shell_exec($this->preFetchShellScript);
	$response = curl_exec($ch);
	if( $this->postFetchShellScript ) shell_exec($this->postFetchShellScript);
	$this->writeJournal('curl', [ 'options'=>$curlOptions, 'info'=>curl_getinfo($ch) ]);
	return $response;
}

private function writeJournal($key, $error) {
	$ts=new DateTime(); $ts=$ts->format('Y-m-d\TH:i:s.v');
	$this->journal[$ts][$key]=$error;
}

// borrowed and adapted from https://gist.github.com/zashme/aa5c578ded5fc99aa65a
private function getMonthRanges($start, $end)
{
	$timeStart = strtotime($start);
	$timeEnd   = strtotime($end);
	$out       = [];
	$milestones[] = $timeStart;
	$timeEndMonth = strtotime('first day of next month midnight', $timeStart);
	while ($timeEndMonth < $timeEnd) {
		$milestones[] = $timeEndMonth;
		$timeEndMonth = strtotime('+1 month', $timeEndMonth);
	}
	$milestones[] = $timeEnd;
	$count = count($milestones);
	for ($i = 1; $i < $count; $i++) {
		$out[] = [
			'firstDay' => date("Y-m-d", $milestones[$i-1]),
			'lastDay'  => date("Y-m-d", $milestones[$i])
		];
	}
	$out[count($out)-1]['lastDay'] = date("Y-m-d", strtotime('+ 1 DAY', strtotime($out[count($out)-1]['lastDay'])));
	return $out;
}

public function setMapping($mapping) {
	$this->mapping=$mapping;
}
public function setRangeChunksPointer($rangeChunksPointer) {
	$this->rangeChunksPointer=$rangeChunksPointer;
}
public function setPreFetchShellScript($preFetchShellScript) {
	$this->preFetchShellScript=$preFetchShellScript;
}
public function setPostFetchShellScript($postFetchShellScript) {
	$this->postFetchShellScript=$postFetchShellScript;
}
public function setApiUrl($apiUrl) {
	$this->$apiUrl=$apiUrl;
}
public function setUserAgent($userAgent) {
	$this->$userAgent=$userAgent;
}
public function setSocks5Proxy($socks5Proxy) {
	if( !preg_match('/:[0-9]+$/', $socks5Proxy) ) return false;
	$this->socks5Proxy=$socks5Proxy;
}
public function setTimeouts( $curlConnectTimeout, $curlTimeout) {
	$this->curlConnectTimeout=$curlConnectTimeout;
	$this->curlTimeout=$curlTimeout;
}


public function getMapping() {
	return $this->mapping;
}
public function getMonthChunks() {
	return $this->rangeChunks;
}
public function getMonthChunksPointer() {
	return $this->rangeChunksPointer;
}
public function getSocks5Proxy() {
	return $this->socks5Proxy;
}
public function getJournal() {
	return $this->journal;
}

} // Class
