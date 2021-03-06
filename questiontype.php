<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Question type class for the cloud 'question' type.
 *
 * @package    qtype
 * @subpackage cloud
 * @copyright  2013 Chris Brucks
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');


/**
 * The cloud 'question' type.
 *
 * @copyright  2013 Chris Brucks
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_cloud extends question_type {
    public function is_real_question_type() {
        return false;
    }

    public function is_usable_by_random() {
        return false;
    }

    public function can_analyse_responses() {
        return false;
    }

    /**
     * Get the extra question field names.
     *
     * Use as a work around for function "initialise_question_instance()".
     * Since "initialise_question_instance()" doesn't read anything from the database
     * but instead just assigns values already loaded to their respective form components
     * we can just list ALL of our extra options irrespective of their tables.
     */
    public function extra_question_fields() {
        // Retain the table name at the beginning of the array for padding reasons
        // when using with "initialise_question_instance()".
        $fields = $this->account_fields();

        // Append only the field names to the array.
        $temp = $this->lb_fields();
        array_shift($temp);
        $fields = array_merge($fields, $temp);

        // Append only the field names to the array.
//        $temp = $this->server_fields();
//        array_shift($temp);
//        $fields = array_merge($fields, $temp);

        return $fields;
    }

    public function account_fields() {
        return array('question_cloud_account', 'username', 'password', 'auth_token', 'region');
    }

    public function lb_fields() {
        return array('question_cloud_lb', 'lb_name', 'vip');
    }

    public function server_fields() {
        return array('question_cloud_server', 'num', 'srv_name', 'imagename', 'slicesize');
    }

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);

//        global $OUTPUT;
//        echo $OUTPUT->notification(var_dump($questiondata));

        $question->servers = $questiondata->options->servers;

    }

    /**
     * Saves question-type specific options
     *
     * This is called by {@link save_question()} to save the question-type specific data
     * @return object $result->error or $result->noticeyesno or $result->notice
     * @param object $question  This holds the information from the editing form,
     *      it is not a standard question object.
     */
    public function save_question_options($question) {
        $this->save_generic_question_options($question, $this->account_fields(), array());
        $this->save_generic_question_options($question, $this->lb_fields(), array('lb_name'=>'notempty'));
        $this->save_generic_question_options($question, $this->server_fields(), array('srv_name'=>'notempty', 'imagename'=>'notempty'), TRUE);
    }

    private function save_generic_question_options($question, $extraquestionfields, $validity_conditions=NULL, $multiple_options=FALSE) {
        global $DB, $OUTPUT;

        if (is_array($extraquestionfields) && count($extraquestionfields)>1) {
            $question_extension_table = array_shift($extraquestionfields);

            $questionidcolname = $this->questionid_column_name();

            if ($multiple_options) {
                // TODO: reuse old entries rather than delete and reinsert
                // APPROACH: count the number of entries and if there are more than we need delete the extras
                // PROBLEMS: distinguishing between already updated entries and old entries
                $DB->delete_records($question_extension_table, array($questionidcolname => $question->id));

//                echo $OUTPUT->notification(var_dump($question));

                // Build and insert one entry for each entry in the array.
                $index_correction = 0;
                foreach (range(0, $question->noservers-1) as $index) {
                    $options = new stdClass();
                    $options->$questionidcolname = $question->id;

                    // Extract single entry from array
                    reset($extraquestionfields);
                    foreach ($extraquestionfields as $field) {
                        if (property_exists($question, $field)) {
                            $field_array = $question->$field;
                            $options->$field = $field_array[$index];
                        }
                    }

                    // Perform checks for valid entry
                    if ($this->valid_form_entry($question_extension_table, $questionidcolname, $options, $validity_conditions)) {
                        // Give each entry a unique id number.
                        $options->num = strval($index + 1 - $index_correction);
                        $DB->insert_record($question_extension_table, $options);
                    } else {
                        // Increment the correction factor which is used to provide an
                        // accurate numbering scheme in the "num" column of the database.
                        $index_correction++;
                    }
                }
            }
            else {
                // TODO: reuse old entries rather than delete and reinsert (Works for single entries but needs
                // to be adapted to delete multiple original entries and replace with a single entry.
                // APPROACH: count the number of records and if there are more than 1 then delete the extras
                $DB->delete_records($question_extension_table, array($questionidcolname => $question->id));

                // Try to use an existing entry rather than create a new one.
                $function = 'update_record';
                $options = $DB->get_record($question_extension_table,
                        array($questionidcolname => $question->id));
                if (!$options) {
                    // There is not an existing entry.  Initialize needed variables.
                    $function = 'insert_record';
                    $options = new stdClass();
                    $options->$questionidcolname = $question->id;
                }
                // Build entry information.
                foreach ($extraquestionfields as $field) {
                    if (property_exists($question, $field)) {
                        $options->$field = $question->$field;
                    }
                }

                // Perform checks for valid entry
                if ($this->valid_form_entry($question_extension_table, $questionidcolname, $options, $validity_conditions)) {
                    // Either update or insert the entry.
                    $DB->{$function}($question_extension_table, $options);
                } else {
                    // The entry is not valid.  Delete the existing entry.
                    $DB->delete_records($question_extension_table, array($questionidcolname => $question->id));
                }
            }
        }
    }

    private function valid_form_entry($question_extension_table, $questionidcolname, $question, $options) {
        global $DB;

        $results = TRUE;
        foreach ($options as $field=>$condition) { // iterate through the $options
            if ($condition === 'unique') {  // Unique field
                $numRecords = $DB->count_records($question_extension_table, array($questionidcolname => $question->$questionidcolname, $field => $question->$field));
                if ($numRecords > 0) {  // field value already exists in another record
                    $results = FALSE;
                }
            } elseif ($condition === 'notempty') {  // Field not empty
                if (empty($question->$field)) {
                    $results = FALSE;
                }
            }
        }
        return $results;
    }

    /**
     * Loads the question type specific options for the question.
     *
     * This function loads any question type specific options for the
     * question from the database into the question object. This information
     * is placed in the $question->options field. A question type is
     * free, however, to decide on a internal structure of the options field.
     * @return bool            Indicates success or failure.
     * @param object $question The question object for the question. This object
     *                         should be updated to include the question type
     *                         specific information (it is passed by reference).
     */
    public function get_question_options($question) {
        if (!isset($question->options)) {
            $question->options = new stdClass();
        }

        $results = $this->get_generic_question_options($question, $this->account_fields());
        $results &= $this->get_generic_question_options($question, $this->lb_fields());
        $results &= $this->get_generic_question_options($question, $this->server_fields(), TRUE);

        return $results;
    }

    private function get_generic_question_options($question, $extraquestionfields, $accept_multiple_records = FALSE) {
        global $CFG, $DB, $OUTPUT;

        if (is_array($extraquestionfields)) {
            $question_extension_table = array_shift($extraquestionfields);

            $extra_data = $DB->get_records($question_extension_table,
                    array($this->questionid_column_name() => $question->id), '',
                    implode(', ', $extraquestionfields));

//                echo $OUTPUT->notification(var_dump($extra_data));

            if ($accept_multiple_records) {
                $question->options->servers = array();
                if ($extra_data) {
                    foreach ($extra_data as $extra_data_single) {
                        $question->options->servers[] = $extra_data_single;
                    }
                } else {
//                    echo $OUTPUT->notification('Failed to load question options from the table ' .
//                            $question_extension_table . ' for questionid ' . $question->id);
                    return false;
                }
//                echo $OUTPUT->notification(var_dump($question->options));
            } else {
                if ($extra_data) {
                    $extra_data = array_shift($extra_data);
                    foreach ($extraquestionfields as $field) {
                        $question->options->$field = $extra_data->$field;
                    }
                } else {
//                    echo $OUTPUT->notification('Failed to load question options from the table ' .
//                            $question_extension_table . ' for questionid ' . $question->id);
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Deletes the question-type specific data when a question is deleted.
     * @param int $question the question being deleted.
     * @param int $contextid the context this quesiotn belongs to.
     */
    public function delete_question($questionid, $contextid) {
        global $DB;

        $this->delete_files($questionid, $contextid);

        $this->delete_generic_question_options($questionid, $this->account_fields());
        $this->delete_generic_question_options($questionid, $this->lb_fields());
        $this->delete_generic_question_options($questionid, $this->server_fields());

        $DB->delete_records('question_answers', array('question' => $questionid));

        $DB->delete_records('question_hints', array('questionid' => $questionid));
    }

    private function delete_generic_question_options($questionid, $extraquestionfields = NULL) {
        global $DB;

        if (is_array($extraquestionfields)) {
            $question_extension_table = array_shift($extraquestionfields);
            $DB->delete_records($question_extension_table,
                    array($this->questionid_column_name() => $questionid));
        }
    }

    public function import_from_xml($data, $question, qformat_xml $format, $extra=null) {
        return parent::import_from_xml($data, $question, $format, $extra);

        // TODO: modify to read in server information.
    }

    public function export_to_xml($question, qformat_xml $format, $extra=null) {
        return parent::export_to_xml($question, $format, $extra);

        // TODO: modify to save out server information.
    }

    public function actual_number_of_questions($question) {
        /// Used for the feature number-of-questions-per-page
        /// to determine the actual number of questions wrapped
        /// by this question.
        /// The question type description is not even a question
        /// in itself so it will return ZERO!
        return 0;
    }

    public function get_random_guess_score($questiondata) {
        return null;
    }
}
