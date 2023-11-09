#!/usr/bin/php
<?php
require_once dirname(__FILE__) . '/agorameter.class.php';

$to = date('Y-m-d', strtotime("-3 DAY"));
$from = date('Y-m-d', strtotime("-33 DAY"));
$agd=new agorameterDownload($from, $to);
$chunks=$agd->getMonthChunks();

echo "Range: [$from,$to]\n";
foreach( $chunks as $k=>$v ) echo "Chunk #$k: [$v[firstDay],$v[lastDay]]\n";

while( ($dc=getNextDataChunk($agd, 3)) !== false ) {
	echo "\n";
	$curChunk=$agd->getMonthChunksPointer()-1;
	if( isset($dc['error']) ) {
		echo "ERROR: Reading chunk #$curChunk finally failed. $dc[error]\n";
		continue;
	}
	echo "Chunk #$curChunk. Received " . count($dc) . " data records\n";
	// ---- do something useful with the data here
	$fk = array_key_first($dc);
	echo "First record ($fk) of chunk #$curChunk:\n";
	print_r($dc[$fk]);
	// ----
}

function getNextDataChunk($agd, $retries=3) {
	for( $r=$retries-1, $count=1; $r>=0; --$r, $count++ ) {
		$dc = $agd->getNextDataChunk();
		$curChunk=$agd->getMonthChunksPointer()-1;
		$index="Chunk#" . sprintf('%02d',$curChunk) . "-" . sprintf('%02d',$count);
		if( isset($dc['error']) && $r>0 ) {
			$wait=($retries-$r)*($retries-$r)+1;
			echo "Reading chunk #$curChunk failed. Retry in $wait seconds. $r retries left.\n";
			$agd->setRangeChunksPointer($curChunk);
			sleep($wait);
		} else break;
	}
	return $dc;
}
