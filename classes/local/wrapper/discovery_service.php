<?php
namespace webservice_mcp\local\wrapper;

use context_system;
use core_external\external_api;
use Exception;
use Throwable;

class discovery_service {
    
    /**
     * Search for Moodle external API functions by query.
     */
    public function search_api(string $query): array {
        global $DB;
        
        $context = context_system::instance();
        external_api::validate_context($context);
        
        $sql = "SELECT name, classname, methodname, component 
                FROM {external_functions} 
                WHERE name LIKE :query OR classname LIKE :classquery";
                
        $params = [
            'query' => '%' . $query . '%',
            'classquery' => '%' . $query . '%'
        ];
        
        $records = $DB->get_records_sql($sql, $params);
        
        return array_values(array_map(fn($r) => (array)$r, $records));
    }

    /**
     * Execute a Moodle external API function dynamically.
     */
    public function execute_api(string $functionname, array $params): array {
        global $USER;
        
        try {
            $context = context_system::instance();
            external_api::validate_context($context);
            
            $info = external_api::external_function_info($functionname);
            if (!$info) {
                throw new Exception("Function {$functionname} not found.");
            }
            
            // Validate and cast the parameters according to the function's description
            $cleanparams = external_api::validate_parameters($info->parameters_desc, $params);
            
            // Execute the function
            $result = call_user_func_array($info->classname . '::' . $info->methodname, array_values($cleanparams));
            
            // Clean and return the result
            $cleanresult = external_api::clean_returnvalue($info->returns_desc, $result);
            
            return [
                'status' => 'success',
                'data' => $cleanresult
            ];
            
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
