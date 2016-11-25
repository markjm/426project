<?php
/*
 * Writes JSON to the client, setting the Content-Type to applicatoin/json
 */
function send_json($arr) {
    header('Content-Type: application/json');
    echo json_encode($arr);
}

/*
 * Writes plain text to the client, setting the Content-Type to text/plain
 */
function send_text($text) {
    header('Content-Type: text/plain');
    echo $text;
}
/*
 * This is like a foreach loop, but in function form. It executes the query in
 * a prepared statement, and passes results one row at a time to the given
 * function.
 *
 * Returns true on success, false on failure.
*/
function iter_stmt_result($stmt, $fn) {
    $stmt->execute();

    $query_result = $stmt->get_result();
    if (!$query_result) {
        return false;
    }

    while ($row = $query_result->fetch_assoc()) {
        $fn($row);
    }

    $query_result->free();
    $stmt->close();
    return true;
}

/*
 * Converts a UNIX timestamp to a MySQL-compatible date.
 */
function sqldatetime($timestamp, $with_time=true) {
    if ($with_time) { 
        return date('Y-m-d H:i:s', $timestamp);
    } else {
        return date('Y-m-d', $timestamp);
    }
}

/*
 * request_match(array("task", "?id", "next"), array("task", "42", "next"))
 * => array("id" => "42")
 *
 * This matches an array, where:
 *  - '?var' in the pattern binds the value in the same position in the input
 *    array
 *  - 'text' checks that the value in the same position in the input array is
 *    the same value. If not, then the function immediately returns false.
 */
function request_match($req_pattern, $req_input) {
    if (count($req_pattern) != count($req_input)) {
        return false;
    }

    $bindings = array();

    foreach ($req_pattern as $x => $pattern) {
        $value = $req_input[$x];

        if ($pattern[0] == '?') {
            $bindings[substr($pattern, 1)] = $value;
        } elseif ($pattern != $value) {
            return false;
        }
    }

    return $bindings;
}

/*
 * A router utilizing request_match.
 *
 *   $rtr = new Router();
 *   $rtr->attach('/', function($vars) {
 *     do_it();
 *   });
 *
 *   $rtr->attach('/purge/?id', function($vars) {
 *     do_it();
 *   });
 *
 *   $rtr->invoke($request);
 */
class Router {
    public $routes = array();

    /*
     * Binds a new routing pattern to the given handler function.
     */
    public function attach($request, $handler) {
        $array_request = explode('/', trim($request, '/'));
        array_push(
            $this->routes,
            array('pattern' => $array_request, 'handler' => $handler));
    }

    /*
     * Invokes this router on the given URL.
     */
    public function invoke($input) {
        if ($input === null) {
            $request = '/';
        }

        $split_input = explode('/', trim($input, '/'));

        foreach ($this->routes as $route) {
            $vars = request_match($route['pattern'], $split_input);
            if ($vars !== false) {
                $handler = $route['handler'];
                $handler($vars);
                return true;
            }
        }

        return false;
    }
}

/*
 * Returns a 404.with the given message.
 */
function http404($msg) {
    header('HTTP/1.1 404 ' . $msg);
    exit;
}

/*
 * Requires the given string to be numeric, or otherwise causes a
 * 404 error.
 */
function force_int($value, $msg) {
    if (!is_numeric($value)) {
        http404($msg);
    }

    return (int)$value;
}

/*
 * Basic string formatter, using names for formatting instead of positional
 * placeholders.
 */
function fmt_string($format, $params) {
    $output = array();

    $in_braces = false;
    $brace_name = '';
    for ($i = 0; $i < strlen($format); $i++) {
        $char = $format[$i];
        if ($char == '{') {
            if ($in_braces) {
                array_push($output, $char);
                $in_braces = false;
            } else {
                $in_braces = true;
            }
        } else if ($char == '}') {
            if ($in_braces) {
                array_push($output, $params[$brace_name]);
                $brace_name = '';
                $in_braces = false;
            } else {
                array_push($output, $char);
            }
        } else if ($in_braces) {
            $brace_name = $brace_name . $char;
        } else {
            array_push($output, $char);
        }
    }

    if ($in_braces) {
        throw new Exception("Expected terminating } in format string");
    }

    return implode('', $output);
}
