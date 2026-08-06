<?php
/**
 * Batch submission of VXU information to CAlifornia Immunization Registry
 *
 * Copyright (C) 2025-2026 MD Support <mdsupport@users.sourceforge.net>
 *
 * @package   Mdhl7
 * @author    MD Support <mdsupport@users.sourceforge.net>
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require __DIR__ . '/vendor/autoload.php';

use Aranyasen\HL7\Message;
use Aranyasen\HL7\Messages\ACK;
use Aranyasen\HL7\Segments\MSH;
use Aranyasen\HL7\Segments\PID;
use Aranyasen\HL7\Segments\PD1;
use Aranyasen\HL7\Segments\ORC;
use Aranyasen\HL7\Segments\RXA;
use Aranyasen\HL7\Segments\OBX;
use Aranyasen\HL7\Segments\RXR;
use Dotenv\Dotenv;
use Mdsupport\Mdpub\DevObj\DevDB;
use Mdsupport\Mdhl7\IR\SoapClientCA;

define('NAME', 'OpenEMR-VXU');
define('VERSION', '.2');

function record_result($hl7, $hl7ResponseString, $id, $env)
{
    if (!$hl7ResponseString || (strlen($hl7ResponseString) < 1)) {
        $hl7ResponseString = '';
        $ackCode = '';
    } else {
        $objHL7Response = new Message($hl7ResponseString);
        $objMSA = $objHL7Response->getSegmentsByName('MSA')[0];
        $ackCode = $objMSA->getAcknowledgementCode();
    }
    
    // Row to be inserted
    $aaRec = [
        'msg_type' => 'VXU',
        'msg_partner' => $env['if.IR.wsdl'],
        'link_source' => 'immunizations',
        'link_key' => $id,
        'msg_body' => $hl7,
        'msg_response' => $hl7ResponseString,
        'msg_result' => $ackCode,
    ];
    
    $dbResult = $GLOBALS['adodb']['db']->autoExecute('hl7log', $aaRec, 'INSERT');
    return $dbResult;
}

/*
 * This script uses hl7log table in the database to track what has been
 * sent and received to IR.
 */
function db_init($connSrc)
{
    $connKeys = preg_grep("/^if\.OpenEMR\.db/",array_keys($connSrc));
    if (count($connKeys) < 4) {
        echo "{$connSrc['_dotenv']}/dotenv/mdhl7.env missing or without database connection entries.";
        return false;
    }
    $adoConn = adoNewConnection($connSrc['if.OpenEMR.dbdriver'] ?? 'mysqli');
    $boolConnected = $adoConn->connect(
        $connSrc['if.OpenEMR.dbhost'],
        $connSrc['if.OpenEMR.dbuser'],
        $connSrc['if.OpenEMR.dbuser.password'],
        $connSrc['if.OpenEMR.dbname']
        );
    if ($boolConnected) {
        $GLOBALS['adodb']['db'] = $adoConn;
        echo "Using {$connSrc['if.OpenEMR.dbname']} on {$connSrc['if.OpenEMR.dbhost']}" . PHP_EOL;
    } else {
        echo "Could not connect to {$connSrc['if.OpenEMR.dbname']} on {$connSrc['if.OpenEMR.dbhost']}.";
        return false;
    }
    
    $adoEMR = new DevDB();
    $checkDB = $adoConn->metaColumnNames('hl7log');
    if (!$checkDB) {
        echo 'No hl7log table. VXU setup incomplete?'.PHP_EOL;
        return false;
    }

    return $adoEMR;
}

function mxu_init() {
    if ($stream = fopen('https://www2a.cdc.gov/vaccines/iis/iisstandards/downloads/mvx.txt', 'r')) {
        // print all the page starting at the offset 10
        echo stream_get_contents($stream);
        
        fclose($stream);
    }
}

// Try getting connection parameters from cached $_SESSION
// For CLI scripts cache will not be available.
$env = [];
if (php_sapi_name() == 'cli') {
    $env = &$_ENV;
    $env['_dotenv'] = getenv("HOME");
} else {
    $env = &$_SESSION;
    $env['_dotenv'] = $_SERVER["HOME"];
}

$dotenv = Dotenv::createImmutable("{$env['_dotenv']}/dotenv", 'mdhl7.env');
$dotenv->load();

$objDB = db_init($env);
if (!$objDB) {
    echo 'Database connection failed.';
    exit();
}

openlog(NAME . ': ' . VERSION, LOG_PID | LOG_PERROR, LOG_USER);

$cairSOAP = new SoapClientCA([
    'username' => $env['if.IR.user'],
    'password' => $env['if.IR.password'],
    'facilityID' => $env['if.IR.facility'],
    'wsdlUrl' => __DIR__ . '/'. $env['if.IR.wsdl']
]);

/* Complex SQL -
 * SELECT immunizations records
 * - must match patient
 * - must have had lot number in inventory (TBD - include manufacturer if lots are not unique)
 * - must not have prior transmissions (may need revision if muliple targets exist)
 * - optionally match ordering provider
 * - optionally match administrator of this immunization
 * - optionally match UoM for administered quantity
 */
$aaExec = [
    'sql' => "
        SELECT imm.*, md.npi md_npi, md.title md_title, md.fname md_fname, md.lname md_lname, IFNULL(du.title,'') dose_units,
            DATE_FORMAT(imm.administered_date,'%Y%m%d') as administered_Ymd,
            cl.fname cl_fname, cl.lname cl_lname, IFNULL(cl.title, 'MA') cl_title,
            mxu.code mxu_code
        FROM immunizations imm
        INNER JOIN (
    	 	SELECT cd.code, cd.code_text manufacturer FROM codes cd
    		INNER JOIN code_types ct ON ct.ct_id=cd.code_type
    		WHERE ct.ct_key='MXU') mxu ON mxu.manufacturer = imm.manufacturer
        INNER JOIN (
            SELECT DISTINCT lot_number FROM drug_inventory) lots ON lots.lot_number = imm.lot_number
        INNER JOIN patient_data pt ON pt.pid = imm.patient_id
        LEFT JOIN hl7log IR ON IR.msg_type='VXU' AND IR.link_source='immunizations' AND imm.id = IR.link_key
        LEFT JOIN users md ON md.id = IFNULL(imm.ordering_provider,pt.providerID)
        LEFT JOIN users cl ON cl.id = IFNULL(imm.administered_by_id,pt.providerID)
        LEFT JOIN list_options du ON du.list_id='drug_units' AND du.option_id=imm.amount_administered_unit
        WHERE IR.link_key IS NULL
        AND LENGTH(imm.cvx_code) > 1
        AND TRIM(IFNULL(imm.lot_number, '')) <> ''
        AND TRIM(IFNULL(pt.fname, '')) <> ''
        AND TRIM(IFNULL(pt.lname, '')) <> ''
        AND pt.DOB IS NOT NULL
        ORDER BY imm.administered_date desc
        LIMIT 100
    ",
    'bind' => [],
    'sfx' => '',
    'return' => 'array',
    'debug' => false,
];

$rsVxu = $objDB->execSql($aaExec);

$ackCount = new stdClass();
$nowdate = date('Ymd');
foreach ($rsVxu as $vxu) {
    syslog(LOG_DEBUG, sprintf("Immunization %d for PID %d", $vxu['id'], $vxu['patient_id']));
    
    // Fix Title
    if ($vxu['md_title'] == 'Dr.') {
        $vxu['md_title'] = 'MD';
    }
    
    if ($vxu['cl_title'] == 'Dr.') {
        $vxu['cl_title'] = 'MD';
    } elseif (empty($vxu['cl_title'])) {
        $vxu['cl_title'] = 'MA';
    }
    
    $rsPt = $objDB->execSql([
        'sql' => "select * from patient_data where pid = ?",
        'bind' => [$vxu['patient_id']],
        'sfx' => "limit 1",
        'return' => 'array',
        'debug' => false,
    ]);
    $patient = $rsPt[0];
    
    // GENERATE HL7 MESSAGE USING SEGMENT OBJECTS
    
    // Create MSH segment
    $objMSH = new MSH();
    $objMSH->setSendingApplication('OPENEMR');
    $objMSH->setSendingFacility($env['if.IR.facility']);
    $objMSH->setReceivingFacility($env['if.IR.region']);
    $objMSH->setDateTimeOfMessage(date('YmdHis') . "+0000");
    $objMSH->setMessageType('VXU^V04^VXU_V04');
    $objMSH->setMessageControlId(date('Ymdhis') . $vxu['cvx_code']. $vxu['id'] );
    $objMSH->setProcessingId('P');
    $objMSH->setVersionId('2.5.1');
    $objMSH->setField(15, 'ER'); // Accept Acknowledgment Type
    $objMSH->setField(16, 'AL'); // Application Acknowledgment Type
//    $objMSH->setField(21, ''); // Message Profile Identifier
//    $objMSH->setField(22, $env['if.IR.region']); // Sending Responsible Organization
    
    // Create PID segment
    $objPID = new PID();
//    $objPID->setSetId('1');
    // Patient identifier list: 1-id, 4-source, 5-id type (MR-Medical Record)
    $objPID->setPatientIdentifierList($vxu['patient_id'] . '^^^OPENEMR^MR');
    // Patient name: 1-Last*, 2-First*,3-Middle, 4-Sfx, 7-Type (L-Legal Name)
    // CAIR - The last, first, and middle names must be alpha characters only (A-Z)
    $strNameAZ = sprintf(
        '%s^%s^^^^^L',
        preg_replace('/[^a-z]/i', ' ', $patient['lname']),
        preg_replace('/[^a-z]/i', ' ', $patient['fname'])
    );
    $objPID->setPatientName($strNameAZ);
    // Date of birth
    $objPID->setDateTimeOfBirth(preg_replace('/-/', '', $patient['DOB']));
    
    // Warning - Library validation override for 'X'
    // $objPID->setSex($sex);
    $sex = 'X';
    if ($patient['sex'] == 'Male') {
        $sex = 'M';
    } else if ($patient['sex'] == 'Female') {
        $sex = 'F';
    } else if ($patient['sex'] == 'Unknown') {
        $sex = 'U';
    }
    $objPID->setField(8, $sex);
       
    // Race
    $race = null;
    if (!empty($patient['race'])) {
        $rsPtRace = $objDB->execSql([
            'sql' => 'select title,notes from list_options where list_id = ? and option_id = ?',
            'bind' => ["race", $patient['race']],
            'sfx' => "limit 1",
            'return' => 'array',
            'debug' => false,
        ]);
        $race = $rsPtRace[0];
    }
    if (!isset($race) || empty($race['title']) || empty($race['notes'])) {
        $race = [
            'notes' => 'PHC1175',
            'title' => 'Prefer Not to Say',
        ];
    }

    $objPID->setRace($race['notes'] . '^' . $race['title'] . '^HL7005');
    
    // Ethnic group
    switch ($patient['ethnicity']) {
        case "hisp_or_latin":
            $objPID->setEthnicGroup('2135-2^Hispanic or Latino^CDCREC');
            break;
        case "not_hisp_or_latin":
            $objPID->setEthnicGroup("2186-5^Not Hispanic or Latino^CDCREC");
            break;
        default: // Unknown
            $objPID->setEthnicGroup("PHC1175^Prefer Not to Say^CDCREC");
    }
    
    // Create PD1 segment
    $objPD1 = new PD1();
    $objPD1->setField(12, 'Y');
    $objPD1->setField(13, date("Ymd", strtotime("-1 days")));
    $objPD1->setField(16, 'A');
    $objPD1->setField(17, date('Ymd', strtotime("-1 days")));
    
    // Create ORC segment
    $objORC = new ORC();
    $objORC->setOrderControl('RE');
    if (isset($vxu['id'])) {
        $objORC->setFillerOrderNumber($vxu['id']);
    }
    
    if (!empty($vxu['md_npi']) && !empty($vxu['md_fname']) &&
        !empty($vxu['md_lname']) && !empty($vxu['md_title'])) {
        $objORC->setOrderingProvider(
            $vxu['md_npi'] . '^' . $vxu['md_lname'] . '^' . $vxu['md_fname'] .
            '^^^^^^NPPES^L^^^NPI^^^^^^^^' . $vxu['md_title']
            );
    } else {
        syslog(LOG_WARNING, 'Did not receive complete ordering provider info. ' .
            'Sending blank ORC-12.');
    }
        
    // Create RXA segment
    $objRXA = new RXA();
    $objRXA->setGiveSubIdCounter('0');
    $objRXA->setAdministrationSubIdCounter('1');
    $objRXA->setDateTimeStartAdministration($vxu['administered_Ymd']);
    // RXA segment requires array
    $objRXA->setAdministeredCode([sprintf("%02d", $vxu['cvx_code']) . '^^CVX']);
    
    // Administered Amount
    if (isset($vxu['amount_administered']) && ($vxu['amount_administered']> 0)) {
        $objRXA->setAdministeredAmount($vxu['amount_administered']);
        $objRXA->setAdministeredUnits([$vxu['dose_units']]);
    } else {
        $objRXA->setAdministeredAmount('999');
    }
        
    // Administration notes requires array 
    $objRXA->setAdministrationNotes(['00^NEW IMMUNIZATION RECORD^NIP001']);
    
    $objRXA->setAdministeringProvider(["^{$vxu['cl_lname']}^{$vxu['cl_fname']}^^^^^^^^^^^^^^^^^^{$vxu['cl_title']}"]);
    
    // Administered at location
    $objRXA->setAdministeredAtLocation(['^^^' . $env['if.IR.orgcode']]);
    
    // Substance Lot Number
    if (isset($vxu['lot_number'])) {
        $objRXA->setSubstanceLotNumber($vxu['lot_number']);
    }
    
    // Substance Expiration Date
    if (isset($vxu['expiration_date'])) {
        $objDateExp = strtotime($vxu['expiration_date']);
        $objRXA->setSubstanceExpirationDate(date('Ymd', $objDateExp));
    }
    
    // Substance manufacturer
    if (isset($vxu['manufacturer'])) {
        $objRXA->setSubstanceManufacturerName([$vxu['mxu_code'] .'^' . $vxu['manufacturer'] . '^MVX']);
    }
        
    // Completion Status
    $objRXA->setCompletionStatus('CP');
    
    // Action coded
    $objRXA->setActionCode('A');

    // Create OBX segment - Funding program eligibility category
    $objOBX1 = new OBX();
    $objOBX1->setID('1');
    $objOBX1->setValueType('CE');
    $objOBX1->setObservationIdentifier('64994-7^Vaccine funding program eligibility category^LN');
    $objOBX1->setObservationSubId('1');
    $objOBX1->setObservationValue('V01^Private Pay/Insurance^HL70064');
    $objOBX1->setObserveResultStatus('F');
    $objOBX1->setDateTimeOfTheObservation($vxu['administered_Ymd']);

    
    // Create OBX segment - Funding source
    $objOBX2 = new OBX();
    $objOBX2->setID('2');
    $objOBX2->setValueType('CE');
    $objOBX2->setObservationIdentifier('30963-3^Vaccine Funding Source^LN');
    $objOBX2->setObservationSubId('1');
    $objOBX2->setObservationValue('PHC70^Private Funds^CDCPHINVS');
    $objOBX2->setObserveResultStatus('F');
    $objOBX2->setDateTimeOfTheObservation($vxu['administered_Ymd']);

    // Create the message
    $objMessage = new Message();
    $objMessage->addSegment($objMSH);
    $objMessage->addSegment($objPID);
    $objMessage->addSegment($objPD1);
    $objMessage->addSegment($objORC);
    $objMessage->addSegment($objRXA);
    $objMessage->addSegment($objOBX1);
    $objMessage->addSegment($objOBX2);
    
    // Convert message to string
    $hl7 = $objMessage->toString(true); // true for pretty print
    
//    print($hl7);
    
    $cairResponseVXU = null;
    try{
        $cairResponseVXU = $cairSOAP->submitSingleMessage($hl7);
    }catch(Exception $e){
        syslog(LOG_ERR, 'Unable to submit VXU to IR: ' . $e->getMessage());
    }
    
    if (is_object($cairResponseVXU) && property_exists($cairResponseVXU, 'return')) {
        $ackCAIR = new Message($cairResponseVXU->return);
        $ackMSA = $ackCAIR->getFirstSegmentInstance('MSA');
        $ackCode = $ackMSA->getField(1);
        $ackCount->$ackCode =  (property_exists($ackCount, $ackCode) ? $ackCount->$ackCode : 0) + 1;
        if ($ackCode != 'AA') {
            // Walk thru ERR segments
            $ackERRs = $ackCAIR->getSegmentsByName('ERR');
            foreach ($ackERRs as $ackERR) {                
                syslog(LOG_ERR, $ackERR->getField(8));
            }
        }
    }
    record_result($hl7, $cairResponseVXU->return, $vxu['id'], $env);
}
// Log summary counts
foreach ($ackCount as $ackCode => $acks) {
    if ($ackCode == 'AA') {
        $ackCode = 'Accepted';
    } elseif ($ackCode == 'AR') {
        $ackCode = 'Rejected';
    } elseif ($ackCode == 'AE') {
        $ackCode = 'Accepted* (See Messages)';
    }
    syslog(LOG_ERR, sprintf('%s: %s', $ackCode, $acks));
}
closelog();