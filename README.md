# Agorameter Data Download
Download German electricity generation, demand, import and export data from the Agora Energiewende think tank website www.agora-energiewende.org

'Agora Energiewende' offers a CSV data download for the time range shown in the charts on its Agorameter website.

This PHP class here loads data for a given time range into a PHP array variable. If the time range spans several months, the data is loaded in monthly chunks. The data itself is provided in hourly resolution.  The electrical power unit is GW (1E9 W), the power price is in EUR.

# Basic use:

```
#!/usr/bin/php
<?php
require_once 'agorameter.class.php';
$agd=new agorameterDownload('2023-08-15', '2023-09-14');
$result=[];
while( ($dc=$agd->getNextDataChunk())!==false ) $result=array_merge($result, $dc);
echo json_encode( $result, JSON_PRETTY_PRINT);
```

# Output:
```
{
    "2023-08-15T00:00:00": {
        "biomass": 4.627,
        "grid_emission_factor": 443.824,
        "hard_coal": 2.737,
        "hydro": 2.525,
        "lignite": 7.547,
        "natural_gas": 8.145,
        "nuclear": 0,
        "other": 2.659,
        "pumped_storage_generation": 0.344,
        "solar": 0,
        "total_conventional_power_plant": 21.432,
        "total_electricity_demand": 49.071,
        "total_grid_emissions": 16197.436,
        "wind_offshore": 2.157,
        "wind_onshore": 6.777,
        "at": -2.454,
        "be": -0.057,
        "ch": -1.355,
        "cz": -2.151,
        "dk": -1.225,
        "fr": -0.748,
        "lu": 0.289,
        "net_total": -11.554,
        "nl": 0.003,
        "no": -1.4,
        "pl": -1.841,
        "power_price": 99.6,
        "se": -0.615
    },
    
.... 743 further data sets

}
```
