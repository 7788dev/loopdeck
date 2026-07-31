<?php
declare(strict_types=1);

$relations = [
    // 系统异常规则重写接管
    "exception" => [
        'think\exception\Handle' => \app\exception\handler\AppExceptionHandler::class,
    ],
];

$_relations = [];
foreach ($relations as $item => $value) {
    if (!empty($value)) {
        foreach ($value as $_key => $_value) {
            $_relations[$_key] = $_value;
        }
    }
}

return $_relations;