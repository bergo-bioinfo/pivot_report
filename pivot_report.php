<?php
/**
 * PLUGIN NAME: Report event pivot table
 * DESCRIPTION: Le but est d'obtenir un rapport avec une ligne par patient_id.
 *              Pivot table d'un rapport pour mettre en colonne les champs pour chaque event (ou instrument) et
 *              pour chaque instance de l'evenement (ou instrument).
 *              
 * VERSION: 1.3
 * AUTHOR: Yec'han Laizet, Pierre Mancini, Quentin Cavaillé
 */

$php_filename = basename(__FILE__);

// Documentation to develop plugin: <website>/Plugins/index.php?REDCapMethod=getEventNames
// Call the REDCap Connect file in the main "redcap" directory
require_once "../redcap_connect.php";

/*
 * Page Logic
 */
if (isset($_GET['report_id']) && (is_numeric($_GET['report_id'])) && $_GET['report_id'] != 0 && isset($_GET['ddl'])) {
    // Get report name
    $report_name = DataExport::getReportNames($_GET['report_id'], !$user_rights['reports']);
    // If report name is NULL, then user doesn't have Report Builder rights AND doesn't have access to this report

    if ($report_name === null) {
        $html .= RCView::div(array('class' => 'red'), $lang['global_01'] . $lang['colon'] . " " . $lang['data_export_tool_180']);
    } else {
        //Get report
        $report = DataExport::getReports($_GET['report_id']);

        // Limit to user's DAG (if user is in a DAG), and if not in a DAG, then limit to the DAG filter
        $userInDAG = (isset($user_rights['group_id']) && is_numeric($user_rights['group_id']));
        $dags = ($userInDAG) ? $user_rights['group_id'] : $report['limiter_dags'];
        //Options
        $combine_checkbox_values = false;
        $outputDags = $report['output_dags'];//false
        $outputSurveyFields = $report['output_survey_fields'];//false
        $outputAsLabels = true; //This parameter is ignored if return_format = "array" since "array" only returns raw values
        $outputHeadersAsLabels = true;
        $hashRecordID = false;
        $dateShiftDates = false;
        $dateShiftSurveyTimestamps = false;
        $sortArray = array();//?
        $removeLineBreaksInValues = true;
        $replaceFileUploadDocId = true;
        $returnIncludeRecordEventArray = false;
        $orderFieldsAsSpecified = true;
        $outputSurveyIdentifier = false;
        $outputCheckboxLabel = true;
        //Get data
        $content = Records::getData(PROJECT_ID, 'array', array(), $report['fields'], $report['limiter_events'], $dags, $combine_checkbox_values, $outputDags, $outputSurveyFields,
                                        $report['limiter_logic'], $outputAsLabels, $outputHeadersAsLabels, $hashRecordID, $dateShiftDates,
                                        $dateShiftSurveyTimestamps, $sortArray, $removeLineBreaksInValues, $replaceFileUploadDocId,
                                        $returnIncludeRecordEventArray, $orderFieldsAsSpecified, $outputSurveyIdentifier, $outputCheckboxLabel, "RECORD");


        $dataDictionary = REDCap::getDataDictionary(PROJECT_ID, 'array');
        $events_mapping = REDCap::getEventNames(True);

        libxml_use_internal_errors(True);
        $metadataOnly = True;
        $projectXML = new SimpleXMLElement(REDCap::getProjectXML(NULL, $metadataOnly));

        $fieldIntrumentMap = mapFieldsInstrument($projectXML);
        $eventMetaData = mapEventsMetaData($projectXML, $fieldIntrumentMap);

        $IDField = getRecordIDField($projectXML);

        $untrimArray = adapt_array($content, $dataDictionary, $events_mapping, $IDField);
        $reportFields = getReportFields($untrimArray);

        $array_for_csv = custom_emptying($untrimArray, $IDField);

        if (REDCap::isLongitudinal()) {
            $file_content = format_csv($array_for_csv, $eventMetaData, $IDField, $reportFields, True, NULL);
        } else {

            foreach ($fieldIntrumentMap as $instru => $value) {
                $instruList[] = $instru;
            }
            // On garde repeatStatus pour projet status
            $instruRepeatStatus = mapInstrumentRepeatStatus($projectXML, $instruList);
            $file_content = format_csv($array_for_csv, $fieldIntrumentMap, $IDField, $reportFields, False, $instruRepeatStatus);
        }
    }
}


/**
 * Tell if the array has no value or has only a value for
 * the IDField.
 */
function onlyIDandComplete($array, $IDField) {

    foreach ($array as $key => $value) {

        $key_tail = end(explode('_', $key));

        // On ne tient pas compte du champ id et des champ complete
        if($key !== $IDField && !is_null($value) && $key_tail !== 'complete') {

            return False;
        }
    }
    return True;
}

/**
 * Supprime les parties vide du tableau.
 * Les records sont les "leaf" de $array 
 * Cette fonction considère comme vide un record que n'a pas d'autre champs rempli que
 * le champs "_complete" et le champ ID du projet. 
 * @param  array  $array   
 * @param  int    $IDField
 * @return array          
 */
function custom_emptying($array, $IDField) {


    foreach ($array as $key => &$value) {        

        if (is_array($value)) {

            if (onlyIDandComplete($value, $IDField)) {

                unset($array[$key]);

            } else {
                $value = custom_emptying($value, $IDField);
                if (is_null($value) || $value === '') {
                    unset($array[$key]);
                }
            }

        } elseif (is_null($value) || $value === '') {
            unset($array[$key]);
        }
    }

//    if (onlyIDandComplete($array, $IDField)) {
  //      return NULL;
//    }

    return $array;
}

/**
 * Give list of fields asked in the current report.
 * @param  array $untrimArray $infoGetData before having empty records removed by custom_emptying.
 * @return array
 */
function getReportFields($untrimArray) {

    $fieldList = [];

    foreach ($untrimArray as $id_content) {

        $repeated_events = $id_content['repeatable']['repeated_events'];
        foreach ($repeated_events as $name => $event) {
            foreach ($event as $instance) {
                foreach ($instance as $field => $value) {
                    if(!in_array($field, $fieldList)) {
                        $fieldList[] = $field;
                    }
                }
            }
        }

        $repeated_instruments = $id_content['repeatable']['repeated_instruments'];
        foreach ($repeated_instruments as $event) {
            foreach ($event as $instru) {
                foreach ($instru as $instance) {
                    foreach ($instance as $field => $value) {
                        
                    }
                }
            }
        }

        foreach ($id_content['unrepeatable'] as $event) {
            foreach ($event as $field => $value) {
                if(!in_array($field, $fieldList)) {
                    $fieldList[] = $field;
                }
            }
        }
    }


    return $fieldList;
}

/**
 * @param  SimpleXMLElement $project  XMLInstance of SimpleXMLElement object with XML metadata 
 *                                    of the project.
 * @return int                        Record ID field
 */
function getRecordIDField($project) {

    $IDField = (string) $project->Study->MetaDataVersion->attributes("https://projectredcap.org")['RecordIdField'];

    return $IDField;
}

/**
 * Classe les instruments en répétable et non-répétable
 * @param  SimpleXMLElement $project        XMLInstance of SimpleXMLElement object with XML metadata 
 *                                          of the project.
 * @param   array           $instrumentList Liste des instruments du projet
 * @return  array                           Structure: {'reapting': [intrument], 'unrepeating': [instrument]}
 */
function mapInstrumentRepeatStatus($project, $instrumentList) {

    $repeatingStatusNode = $project->Study->GlobalVariables->children('https://projectredcap.org')->RepeatingInstrumentsAndEvents->RepeatingInstruments->RepeatingInstrument;


    foreach ($repeatingStatusNode as $repeatingInstrumentNode) {
        $instruRepeatStatus['repeating'][] = (string) $repeatingInstrumentNode['RepeatInstrument'];
    }


    foreach ($instrumentList as $key => $instru) {
        if (! in_array($instru, $instruRepeatStatus['repeating'])) {
            $instruRepeatStatus['unrepeating'][] = $instru;
        }
    }

    return $instruRepeatStatus;
}

/**
 * Transforme les données de adapt_array en csv
 * @param  array      $infoGetData         Données formatées par adapt_array()
 * @param  array      $metaData            
 * @param  int        $IDField             Record ID field
 * @param  array      $reportFields        List of fields ask in the current report
 * @param  bool       $projectLongitudinal 
 * @return string/csv                      csv string to be display or written in a .csv file
 */
function format_csv(array $infoGetData, array $metaData, $IDField, $reportFields, $projectLongitudinal, $instruRepeatStatus) {
    /*
        Le document csv final est divisé en trois parties: la droite de tableau agrège les élèments 
        non répétables, le centre du tableau agrège les instances
        répétées, la gauche du tableau agrège les events répétés.
     */

    /**
     * Event et instrument non répétables
     * @param  array $infoGetData 
     * @param  array $IDField
     * @param  array $reportFields     
     * @return array              
     */
    function create_unrepeatables_table($infoGetData, $metaData, $IDField, $reportFields) {

        $event_header = array(0 => NULL);
        $instance_header = array(0 => NULL);
        $instrument_header = array(0 => NULL);
        $field_header = array(0 => $IDField);
        $lines = array();

        $event_list = [];

        // Liste des event pour les éléments non répétables
        foreach ($infoGetData as $id => $id_content) {
            $unrepeat_content = $id_content['unrepeatable'];
            foreach ($unrepeat_content as $event => $event_content) {
                if (!in_array($event, $event_list)) {
                    $event_list[] = $event;
                }
            }
        }

        // Creation des headers seulement
        foreach ($event_list as $event_name) {

            foreach ($metaData[$event_name] as $instru => $fields) {

                foreach ($fields as $field_meta) {

                    // On créer une unité répétable pour le header final
                    // On ne duplique pas le champ patient_id
                    if ($field_meta != $IDField && in_array($field_meta, $reportFields)) {
                        $field_header[] = $field_meta;
                        $instrument_header[] = $instru;
                        $instance_header[] = 'unrepeatable';
                        $event_header[] = $event_name;
                    }
                }
            }
        }

        foreach ($infoGetData as $id => $id_content) {
            
            $unrepeat_content = $id_content['unrepeatable'];

            // Patient id passé à la ligne en dur
            $line = array(0 => $id);
            // La marge à un taille de 1
            for ($i=1; $i < count($field_header); $i++) {

                if (isset($unrepeat_content[$event_header[$i]]) && isset($unrepeat_content[$event_header[$i]])) {

                    $value = $unrepeat_content[$event_header[$i]][$field_header[$i]];

                    if (is_array($value)) {
                        $value = concat_checkbox($value);
                    }
                    $line[$i] = $value;
                } else {

                    $line[$i] = NULL;
                }

            }
            $lines[$id] = $line;
        }

        $unrepeatables_table = ['event' => $event_header,
                                'instance' => $instance_header,
                                'instrument' => $instrument_header,
                                'field' => $field_header,
                                'lines' => $lines];

        return $unrepeatables_table;
    }


    /**
     * @param  array  $infoGetData  Value returned by adapt_array()
     * @param  array  $metaData     
     * @param  int    $IDField      
     * @param  array  $reportFields 
     * @return array                Version array de la partie csv des évènements répétés
     */
    function create_event_table(array $infoGetData, array $metaData, $IDField, $reportFields) {

        $event_header = array(0 => NULL);
        $instance_header = array(0 => NULL);
        $instrument_header = array(0 => NULL);
        $field_header = array(0 => $IDField);
        $lines = array();

        // Dit combien d'instance prévoir dans le header
        $maximums = max_instance_by_event($infoGetData);

        // Creation des headers seulement
        foreach ($maximums as $event_name => $max) {
            if ($max === 'unrepeatable') {
                $max = 1;
            }

            $sub_field_header = array();
            $sub_instrument_header = array();
            $sub_events_header = array();

            // Création des sous header field et sous header instru
            foreach ($metaData[$event_name] as $instru => $fields) {

                foreach ($fields as $field_meta) {

                    // On créer une unité répétable pour le header final
                    // On ne duplique pas le champ patient_id
                    if ($field_meta != $IDField && in_array($field_meta, $reportFields)) {
                        $sub_field_header[] = $field_meta;
                        $sub_instrument_header[] = $instru;
                        $sub_events_header[] = $event_name;
                    }
                }         
            }

            $nb_field = count($sub_field_header);

            $nb_instance = 1;
            do {
                // $instrument_header
                $field_header = array_merge($field_header, $sub_field_header);
                $instrument_header = array_merge($instrument_header, $sub_instrument_header);
                $sub_instance_header = array_fill(0, $nb_field, $nb_instance);
                $instance_header = array_merge($instance_header, $sub_instance_header);
                $event_header = array_merge($event_header, $sub_events_header);
            } while ($max > $nb_instance++);
        }

        foreach ($infoGetData as $id => $id_content) {

            $repeat_content = $id_content['repeatable']['repeated_events'];

            // Patient id passé à la ligne en dur
            $line = array(0 => $id);
            // La marge à un taille de 1
            for ($i=1; $i < count($field_header); $i++) {

                if (is_numeric($maximums[$event_header[$i]])) {
                    if (isset($repeat_content[$event_header[$i]]) && isset($repeat_content[$event_header[$i]][$instance_header[$i]])) {
                        $value = $repeat_content[$event_header[$i]][$instance_header[$i]][$field_header[$i]];
                        if (is_array($value)) {
                            $value = concat_checkbox($value);
                        }
                        $line[$i] = $value;
                    } else {
                        $line[$i] = NULL;
                    }

                } else {
                    throw new Exception("Can't find event's repeat status");
                }
            }
            $lines[$id] = $line;
        }

        $event_table = array('event' => $event_header,
                             'instance' => $instance_header,
                             'instrument' => $instrument_header,
                             'field' => $field_header,
                             'lines' => $lines);

        return $event_table;
    }

    /**
     *  Nb: Après fusion, entre la table event et instrument les headers 
     *  ne sont pas sur la même ligne :
     *  table_instrument/event_header + table_event/event_header
     *  table_instrument/instrument_header + table_event/instance_header
     *  table_instrument/instance_header  + table_event/instrument_header
     *  table_instrument/field_header + table_event/field_header
     *  table_instrument/lines + table_event/lines -> patient_id n'est pas dupliqué
     *
     * @param array $instrument_table
     * @param array $event_table
     * 
     * @return array Representation of the full table
     */
    function merge_tables($unrepeatables_table, $instrument_table, $event_table) {

        // La colonne patient_id n'est pas dupliquée, on l'enlève des tables event, instrument
        array_shift($event_table['event']);
        array_shift($event_table['instance']);
        array_shift($event_table['instrument']);
        array_shift($event_table['field']);
        array_shift($instrument_table['event']);
        array_shift($instrument_table['instance']);
        array_shift($instrument_table['instrument']);
        array_shift($instrument_table['field']);

        $table[0] = array_merge($unrepeatables_table['event'], 
                                $instrument_table['event'],
                                $event_table['event']);
        $table[1] = array_merge($unrepeatables_table['instrument'],
                                $instrument_table['instrument'],
                                $event_table['instance']);
        $table[2] = array_merge($unrepeatables_table['instance'],
                                $instrument_table['instance'],
                                $event_table['instrument']);
        $table[3] = array_merge($unrepeatables_table['field'],
                                $instrument_table['field'],
                                $event_table['field']);

        if (array_keys($instrument_table['lines']) === array_keys($event_table['lines']) && array_keys($unrepeatables_table['lines']) === array_keys($instrument_table['lines'])) {
            foreach ($event_table['lines'] as $id => $value) {
                // La colonne patient_id n'est pas dupliquée
                array_shift($instrument_table['lines'][$id]);
                array_shift($event_table['lines'][$id]);

                $table[] = array_merge($unrepeatables_table['lines'][$id], $instrument_table['lines'][$id], $event_table['lines'][$id]);
            }
        } else {
            throw new Exception("Error, id not matching between unrepeatable, instrument and/or event tables");
        }

        return $table;
    }


    /**
     * @param  array  $infoGetData  Tableau retourné par adapt_array()
     * @param  array  $metaData     
     * @param  int    $IDField     
     * @param  array  $reportFields 
     * @return array               
     */
    function create_instrument_table(array $infoGetData, array $metaData, $IDField, $reportFields) {

        function mapInstrumentData($infoGetData) {
            foreach ($infoGetData as $id_content) {
                $events_array = $id_content['repeatable']['repeated_instruments'];
                foreach ($events_array as $event_name => $event_content) {
                    foreach ($event_content as $instrument_name => $instrument) {
                        $mapInstrumentEvent[$instrument_name] = $event_name;
                    }
                }
            }
            return $mapInstrumentEvent;
        }

        $event_header = array(0 => NULL);
        $instrument_header = array(0 => NULL);
        $instance_header = array(0 => NULL);
        $field_header = array(0 => $IDField);
        $lines = array();

        // Pour le test ajouter un nouveau event avec des instruments indépendants
        $maximums = max_instance_by_instrument($infoGetData, NULL);
        $instrumentData = mapInstrumentData($infoGetData);

        // Tri reportFields
        foreach ($maximums as $instrument => $max) {
            $event = $instrumentData[$instrument];

            $sub_field_header = array();

            foreach ($metaData[$event][$instrument] as $field_meta) {

                if (in_array($field_meta, $reportFields)) {
                    $sub_field_header[] = $field_meta;
                }
            }

            $sub_instrument_header = array_fill(0, count($sub_field_header), $instrument);

            $nb_field = count($sub_field_header);
            $sub_events_header = array_fill(0, $nb_field, $event);

            $count = 1;
            do {
                $field_header = array_merge($field_header, $sub_field_header);

                $instrument_header = array_merge($instrument_header, $sub_instrument_header);

                $sub_instance_header = array_fill(0, $nb_field, $count);
                $instance_header = array_merge($instance_header, $sub_instance_header);
                $event_header = array_merge($event_header, $sub_events_header);

            } while ($max > $count++);
        }


        foreach ($infoGetData as $id => $id_content) {

            $repeat_content = $id_content['repeatable']['repeated_instruments'];

            // Patient id passé à la ligne en dur
            $line = array(0 => $id);
            for ($i=1; $i < count($field_header); $i++) {
                if (isset($repeat_content[$event_header[$i]]) && isset($repeat_content[$event_header[$i]][$instrument_header[$i]][$instance_header[$i]])) {
                    $value = $repeat_content[$event_header[$i]][$instrument_header[$i]][$instance_header[$i]][$field_header[$i]];
                    if (is_array($value)) {
                        $value = concat_checkbox($value);
                    }
                    $line[$i] = $value;
                } else {
                    $line[$i] = NULL;
                }
            }
            $lines[$id] = $line;
        }

        $instrument_table =  array('event' => $event_header,
                                   'instrument' => $instrument_header,
                                   'instance' => $instance_header,
                                   'field' => $field_header,
                                   'lines' => $lines);

        return $instrument_table;
    }

    /**
     * - Créé un table à partir de données venant de projet classique (non-longitudinaux)
     * - Retourne un array convertible directement en string csv car il n'y a pas de fusion de table à faire
     * 
     * @param  array     $infoGetData         Données formatées par adapt_array()
     * @param  array     $metaData            $eventMetaData pour les projets longitudinaux ou 
     *                                        $fieldIntrumentMap pour les projets classiques
     * @param  int       $IDField             Record ID field
     * @param  array     $reportFields        
     * @param  array     $instruRepeatStatus  Output de la fonction mapInstrumentRepeatStatus()
     * @return string/csv                     csv string to be display or written in a .csv file
     */
    function classic_project_table(array $infoGetData, array $metaData, $IDField, $reportFields, $instruRepeatStatus) {

        $instrument_header = array(0 => NULL);
        $instance_header = array(0 => NULL);
        $field_header = array(0 => $IDField);
        $lines = array();

        // Pour le test ajouter un nouveau event avec des instruments indépendants
        $maximums = max_instance_by_instrument($infoGetData, $instruRepeatStatus);

        foreach ($maximums as $instrument => $max) {

            $sub_field_header_raw = $metaData[$instrument];

            // On ne duplique pas le champ patient_id
            $sub_field_header = array();
            foreach ($sub_field_header_raw as $field) {
                if ($field != $IDField && in_array($field, $reportFields)) {
                    $sub_field_header[] = $field;
                }
            }

            $nb_field = count($sub_field_header);

            $sub_instrument_header = array_fill(0, $nb_field, $instrument);

            $count = 1;
            do {
                $field_header = array_merge($field_header, $sub_field_header);
                $instrument_header = array_merge($instrument_header, $sub_instrument_header);
                $sub_instance_header = array_fill(0, $nb_field, $count);
                $instance_header = array_merge($instance_header, $sub_instance_header);

            } while ($max > $count++);
        }

        foreach ($infoGetData as $id => $id_content) {

            $repeat_content = $id_content['repeatable']['repeated_instruments'];
            $unrepeat_content = $id_content['unrepeatable'];

            // Patient id passé à la ligne en dur
            $line = array(0 => $id);
            // La marge à un taille de 1
            for ($i=1; $i < count($field_header); $i++) {

                if (is_numeric($maximums[$instrument_header[$i]])) {

                    if (isset($repeat_content[''][$instrument_header[$i]]) && isset($repeat_content[''][$instrument_header[$i]][$instance_header[$i]])) {

                        $value = $repeat_content[''][$instrument_header[$i]][$instance_header[$i]][$field_header[$i]];

                        if (is_array($value)) {
                            $value = concat_checkbox($value);
                        }
                        $line[$i] = $value;

                    } else {

                        $line[$i] = NULL;
                    }


                } elseif ($maximums[$instrument_header[$i]] === 'unrepeatable') {

                    $value = $unrepeat_content[''][$field_header[$i]];

                    if (is_array($value)) {
                        $value = concat_checkbox($value);
                    }
                    $line[$i] = $value;

                } else {
                    throw new Exception("Can't find event's repeat status");
                }
            }

            $lines[$id] = $line;
        }

        $classic_table = array(0 => $instrument_header,
                               1 => $instance_header,
                               2 => $field_header);
        $headers_size = count($classic_table);

        for ($i=0; $i < count($lines) + $headers_size; $i++) { 
            $classic_table[$headers_size + $i] = current($lines);
            next($lines);
        }

        return $classic_table;
    }

    if ($projectLongitudinal) {
        $event_table = create_event_table($infoGetData, $metaData, $IDField, $reportFields);
        $instrument_table = create_instrument_table($infoGetData, $metaData, $IDField, $reportFields);
        $unrepeatables_table = create_unrepeatables_table($infoGetData, $metaData, $IDField, $reportFields);
        $table = merge_tables($unrepeatables_table, $instrument_table, $event_table);
    } else {
        $table = classic_project_table($infoGetData, $metaData, $IDField, $reportFields, $instruRepeatStatus);
    }


    $csv = '';
    foreach ($table as $line) {
        $csv .= array_to_csv($line).PHP_EOL;
    }

    return $csv;
}


/**
 * - Classe les données par type "repeatable", "unrepeatable"
 * - Les valeurs des champs à choix multiples sont changés par leur labels
 * - Change le numéro de l'évènement pour le nom qui correspond
 * - Le but est de créer un array avec les même info mais plus facilement lisible par l'humain
 * 
 * @param  array  $content         Output of Records::getData with 'array' as second argument.
 * @param  array  $events_mapping  Mapping of events their redcap id
 * @param  array  $dataDictionary  Output of REDCap::getDataDictionary
 * @return array                   {patient_id: {event: {instance_nb: {field: value}}}}
 */
function adapt_array(array $content, array $dataDictionary, array $events_mapping, $IDField) {

    /**
     * - Remplace les values par les labels
     * - Pour les checkbox ne garde que les éléments qui ont une valeur 
     * @param  array  $fields         [description]
     * @param  array  $dataDictionary [description]
     * @return                         Subarray contenant les champs et leurs valeurs
     */
    function parse_fields(array $fields, array $dataDictionary) {

        foreach ($fields as $field => $field_value) {
            $rawChoices = $dataDictionary[$field]['select_choices_or_calculations'];

            if ($rawChoices) {

                $choices_mapping = get_choice_labels($rawChoices);

                // Cas des checkbox
                if (is_array($field_value)) {
                    $treated_fields[$field] = array();
                    // On ne garde que les valeurs selectionnées par l'utilisateur du formulaire
                    foreach ($field_value as $key => $bool_choice) {
                        if ($bool_choice) array_push($treated_fields[$field], $choices_mapping[$key]);
                    }
                // Cas des listes déroulantes
                } else {
                    $treated_fields[$field] = $choices_mapping[$field_value];
                }
            // Champ text
            } else {
                $treated_fields[$field] = $field_value;
            }
        }
        return $treated_fields;
    }


    /**
     * 
     * @param  array  $instances      Instance d'évènement ou d'instrument
     * @param  array  $dataDictionary [description]
     * @return array                  Subarray contenant les info retravaillées des instances
     */
    function parse_instances(array $instances, array $dataDictionary) {
        foreach ($instances as $instance_nb => $instance_content) {
            $treated_instances[$instance_nb] = parse_fields($instance_content, $dataDictionary);
        }
        return $treated_instances;
    }


    /**
     * Traite les données des instruments répétables et des events répétables
     * @param  array  $repeat_events  [description]
     * @param  array  $dataDictionary [description]
     * @return array                   {'repeated_events': , 'repeated_instruments': }
     */
    function manage_repeatables(array $repeat_events, array $dataDictionary, array $events_mapping) {
        foreach ($repeat_events as $event_id => $event_content) {
            $event = $events_mapping[$event_id];
            
            // print("event: $event<br/>");
            foreach ($event_content as $instrument_name => $instrument_content) {
                // print("instrument: $instrument_name <br/>");

                if ($instrument_name == '') {
                    $repeatables['repeated_events'][$event] = parse_instances($instrument_content, $dataDictionary);
                } else {
                    // print("Dans le else, instrument: $instrument_name <br/>");
                    $repeatables['repeated_instruments'][$event][$instrument_name] = parse_instances($instrument_content, $dataDictionary);
                }
            }
        }
        return $repeatables;
    }

    ##########

    foreach ($content as $id => $id_content) {
        // On utilise le mot subarray à la place de event car l'une des clés des peut-être la cdc 
        // 'repeat_instances', ce qui ne correspond pas à un event.
        foreach ($id_content as $subarray_id => $subarray_content) {
            // $event_nb -> l'identifiant de l'event à travers la base redcap
            // Gestion des évènements répétés
            if ($subarray_id == 'repeat_instances') {
                $out[$id]['repeatable'] = manage_repeatables($subarray_content, $dataDictionary, $events_mapping);
            } else {

                $event = $events_mapping[$subarray_id];
                $out[$id]['unrepeatable'][$event] = parse_fields($subarray_content, $dataDictionary);

            }
        }
    }

    return $out;
}

/**
 * @param  SimpleXMLElement $project             XMLInstance of SimpleXMLElement object with XML metadata 
 *                                               of the project.
 * @param  array            $fieldInstrumentMap
 * @return array                                 Structure: {event_name: {instrument_name: [field_names]}}
 */
function mapEventsMetaData($project, $fieldIntrumentMap) {

    foreach ($project->Study->MetaDataVersion->StudyEventDef as $eventNode) {

        $event = explode('.', $eventNode['OID'])[1];

        foreach ($eventNode as $formNode) {

            $instrument = explode('.', $formNode['FormOID'])[1];

            $metaData[$event][$instrument] = $fieldIntrumentMap[$instrument];

        }
    }

    return $metaData;
}

/**
 * @param  SimpleXMLElement $project  XMLInstance of SimpleXMLElement object with XML metadata 
 *                                    of the project.
 * @return array                      {instrument_name: [field_names]}
 */
function mapFieldsInstrument($project) {

    foreach ($project->Study->MetaDataVersion->ItemGroupDef as $itemGroupDef) {

        // Le nom l'instrument à garder se trouve dans la première partie de l'attribut 
        // OID de la balise <ItemGroupDef>

        // Attention, il y a un <ItemGroupDef> pour le seul champ '_complete'
        
        // Les noms de champ se trouvent dans l'attribut redcap:Variable de la balise 
        // <ItemRef>

        $instrument_name = explode('.', $itemGroupDef['OID'])[0];
        $suffix = explode('.', $itemGroupDef['OID'])[1];

        // On en tient pas compte du <ItemGroupDef> avec '_complete'
        if (end(explode('_', $suffix)) !== 'complete') {

            // $instruments[$instrument_name] = array();

            foreach ($itemGroupDef->ItemRef as $itemRef) {

                // Précision du namespace redcap
                $field = (string) $itemRef->attributes("https://projectredcap.org")['Variable'];
                
                // Cast to string, cf: http://php.net/manual/en/simplexmlelement.attributes.php#97266
                if(!in_array($field, $instruments[$instrument_name])) {
                    $instruments[$instrument_name][] = $field;
                }
            }
        }
    }

    return $instruments;
}

/**
 * Pour déterminer la taille finale du header on doit compter pour chaque events le nombre maximal 
 * d'instance présents pour tout les patient_id
 * @param  array  $infoGetData Tableau retourné par adapt_array()
 * @return array               Structure: {event: max num of instances or 'unrepeatable'}
 */
function max_instance_by_event(array $infoGetData) {
    // Structure :
    // {event: max num of instances}
    
    $maximums = array();

    foreach ($infoGetData as $id_content) {
        $events_array = $id_content['repeatable']['repeated_events'];
        foreach ($events_array as $event_name => $event_content) {
            if ($maximums[$event_name] < count($event_content)) {
                $maximums[$event_name] = count($event_content);
            }
        }

    }

    return $maximums;
}

/**
 * Fait la même chose que la fonction max_instance_by_event() mais pour les répétitions d'instrument
 * @param  array  $infoGetData        Tableau retourné par adapt_array()
 * @param  array  $instruRepeatStatus Permet de retrouver les instruments non répétables.
 * @return array                      Structure: {event: max num of instances or 'unrepeatable'}
 */
function max_instance_by_instrument(array $infoGetData, $instruRepeatStatus) {

    $maximums = array();

    foreach ($infoGetData as $id_content) {
        $events_array = $id_content['repeatable']['repeated_instruments'];
        foreach ($events_array as $event_name => $event_content) {
            foreach ($event_content as $instrument_name => $instrument) {
                if ($maximums[$instrument_name] < count($instrument)) {
                    $maximums[$instrument_name] = count($instrument);
                }
            }
        }

        foreach ($instruRepeatStatus['unrepeating'] as $instrument) {
            $maximums[$instrument] = 'unrepeatable';
        }

    }

    return $maximums;
}

/**
 * Checkbox values are in the same .csv cell
 * @param  array  $checkbox_values
 * @return string                 
 */
function concat_checkbox(array $checkbox_values) {

    return implode(', ', $checkbox_values);
}

/**
  * Formats a line (passed as a fields  array) as CSV and returns the CSV as a string.
  * Adapted from http://us3.php.net/manual/en/function.fputcsv.php#87120
  *
  * Doesn't work on nested arrays.
  */
function array_to_csv(array $fields, $delimiter = ',', $enclosure = '"', $encloseAll = false) {

    $delimiter_esc = preg_quote($delimiter, '/');
    $enclosure_esc = preg_quote($enclosure, '/');

    $output = array();

    foreach ($fields as $field) {

        // Enclose fields containing $delimiter, $enclosure or whitespace
        if ($encloseAll || preg_match("/(?:${delimiter_esc}|${enclosure_esc}|\s)/", $field)) {
            $output[] = $enclosure . str_replace($enclosure, $enclosure . $enclosure, $field) . $enclosure;
        }
        else {
            $output[] = $field;
        }
    }
    return implode($delimiter, $output);
}

/**
 * Map dataDictionary's values with labels.
 * @param  txt    $rawChoices Choices, with values and labels in raw txt format.
 * @return array              Mapping of dataDictionary's labels and values.
 */
function get_choice_labels($rawChoices) {

    $splitChoices = array_map(trim, explode('|', $rawChoices));

    foreach ($splitChoices as $value_label) {

        $value_label = array_map(trim, explode(',', $value_label));

        $mapping[$value_label[0]] = $value_label[1];
    }

    return $mapping;
}

/**
 * - Pivote les lignes instance de la version "array" d'un report
 * - Concatène la valeur des checkbox en une seule chaine de caractère.
 * 
 * @param  array  $content        Output of Records::getData with 'array' as second argument.
 * @param  [type] $dataDictionary [description]
 * @return [type]                 {patient_id: {instance_nb: {field: value}}}
 */
function pivot_array_no_events($content, $dataDictionary) {
    foreach ($content as $id => $instances) {
        // Sur les projets classiques (non longitudinaux) il n'y a qu'un seul évènement
        foreach ($instances['repeat_instances'] as $event_nb => $event_content) {
            //TODO: finir la function, la tester sur multipli
            // -> pour les projets non longitudinaux
        }
    }
}

/**
 * Legacy
 * @param  txt/csv  $csv_content  Brut content of the file before transformation = Records::getData with 'csv' as second argument.
 * @param  array    $events       List of event name without arms name (not ids) 
 * @return txt/csv                Brut content of the file after transformation
 */
function pivot_csv($csv_content, $events) {
    // Open connection to create file in memory and write to it
    //$fp = fopen('php://output', "x+");
    $fp = fopen('php://memory', "x+");

    //Parse csv
    $csv_lines = explode("\n", $csv_content);

    //Manage headers
    $csv_header = str_getcsv($csv_lines[0]);
    $nb_fields = count($csv_header);
    unset($csv_lines[0]);
    $events_header = array();
    $fields_header = array();
    foreach ($events as $event_name) {
        $events_header = array_merge($events_header, array_fill(0, $nb_fields, $event_name));
        $fields_header = array_merge($fields_header, $csv_header);
    }

    // Add header rows to CSV
    fputcsv($fp, $events_header);
    fputcsv($fp, $fields_header);

    //Manage data
    $array_content = array();
    foreach ($csv_lines as $id => $line) {
        $parts = str_getcsv($csv_lines[$id]);

        if (!isset($array_content[$parts[0]])) {
            $array_content[$parts[0]] = array();
            foreach ($events as $event_name) {
                $array_content[$parts[0]][$event_name] = array_fill(0, $nb_fields, "");
            }
        }
        $array_content[$parts[0]][$parts[1]] = $parts;
        unset($csv_lines[$id]);
    }

    foreach ($array_content as $id => $data_events) {
        $data_line = array();
        foreach ($events as $event_name) {
            $data_line = array_merge($data_line, $data_events[$event_name]);
        }

        fputcsv($fp, str_replace("  |", "\n|", $data_line));
    }


    // Open file for reading and output to user
    fseek($fp, 0);
    $csv_file_contents = stream_get_contents($fp);
    fclose($fp);
    return $csv_file_contents;
}

function pprint_r($content) {

    echo '<pre>';
    print_r($content);
    echo '</pre>';

}


?>
<?php
// OPTIONAL: Display the project header
if (!isset($_GET["ddl"])) {
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

    $report_names = array("" => "Select a report");
    foreach (DataExport::getReportNames() as $id => $report_obj) {
        if (is_array($report_obj)) {
            $report_names[strval($report_obj["report_id"])] = $report_obj["title"];
        } else {
            $report_names[strval($id)] = $report_obj;
        }
    }
} ?>
<?php if (!isset($_GET["ddl"])) : ?>
    <h3 style="color:#800000;">
        Tableau croisé (Pivot table) d'un rapport pour mettre en colonne les champs pour chaque event.
    </h3>
    <div class="chklist" style="padding:8px 15px 7px;margin:5px 0 20px;max-width:770px;">
        <form action="<?php echo(basename(__FILE__)); ?>?pid=<?php echo PROJECT_ID; ?>&report_id=<?php echo $_GET["report_id"]; ?>&ddl=" method="post">
            <span class="label">Choisir un rapport</span>
            <?php
            print RCView::select(array(
                        'class' => "x-form-text x-form-field",
                        'onchange' => 'window.location.href="' . PAGE_FULL . "?pid=" . $_GET['pid'] . '&report_id=' . '"+this.value'
                            ), $report_names, $_GET['report_id']);
            ?>
            <input type="submit" value="Télécharger" name="submit">
        </form>
    </div>
    <h3 style="color:red;">
        Prérequis : l'identifiant doit être  présent dans le rapport!
    </h3>
<?php endif; ?>
<?php
// OPTIONAL: Display the project footer
if (!isset($_GET["ddl"])) {
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
} else {
    //Download csv file
    $date = date("Y-m-d_H:i:s");
    header("Content-type: text/csv");
    header("Content-Disposition: attachment; filename=Tableau_croisé_$date.csv");
    header("Pragma: no-cache");
    header("Expires: 0");
    print($file_content);
}
