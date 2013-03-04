<?php
// Copyright 2004-present Facebook. All Rights Reserved.

require_once "base.php";

$args = $argv;
array_shift($args);
$target = array_shift($args);

foreach ($args as $arg) {
  require_once $arg;
}

if (substr($target, -4) == '.cpp') {
  ob_start();
  printf("// @"."generated by idl/class_map.php\n");
  printf("#include <runtime/base/base_includes.h>\n");
  printf("#include <runtime/ext/ext.h>\n");
  printf("namespace HPHP {\n");
  write_constants(false);
  echo "const char *g_class_map[] = {\n";
  echo '  (const char *)ClassInfo::IsSystem, NULL, "",';
  echo ' "", NULL, NULL, NULL, ', "\n";
  foreach ($funcs as $func) {
    functionClassMap($func);
  }
  printf("  NULL,\n");
  printf("  NULL,\n");

  printf('  "false", (const char*)4, "b:0;",'."\n");
  printf('  "true", (const char*)4, "b:1;",'."\n");
  printf('  "null", (const char*)2, "N;",'."\n");
  foreach ($constants as $constant) {
    constantClassMap($constant);
  }
  printf('  "SID", (const char *)((offsetof(SystemGlobals, k_SID) - '.
         'offsetof(SystemGlobals, stgv_Variant)) / sizeof(Variant)), '.
         "(const char *)1,\n");
  printf("  NULL, // End of constants\n");
  printf("  NULL,\n");

  foreach ($classes as $cls) {
    classClassMap($cls);
  }
  printf("  NULL,\n");
  printf("  NULL\n");
  printf("};\n");
  printf("}\n");
  file_put_contents($target, ob_get_clean());
} else {
  ob_start();
  printf("#ifndef _H_SYSTEM_CONSTANTS\n");
  printf("#define _H_SYSTEM_CONSTANTS\n");
  printf("// @"."generated by idl/class_map.php\n");
  printf("namespace HPHP {\n");
  printf("class StaticString;\n");
  printf("class Variant;\n");
  write_constants(true);
  printf("}\n");
  printf("#endif\n");
  file_put_contents($target, ob_get_clean());
}
return true;

function functionClassMap($func, $cls = null) {
  if (!array_key_exists('flags', $func)) {
    var_dump($func);
    exit(1);
  }

  $attribute = (($func['flags'] &
                (IsProtected|IsPrivate|IsPublic|IsAbstract|IsStatic|IsFinal|
                 AllowIntercept|NoProfile|ContextSensitive|HipHopSpecific|
                 VariableArguments|RefVariableArguments|MixedVariableArguments|
                 HasDocComment|NeedsActRec|FunctionIsFoldable|NoInjection|
                 NoEffect|HasOptFunction)) |
                IsSystem | IsNothing);
  if ($attribute & RefVariableArguments) {
    $attribute |= VariableArguments;
  }
  if ($attribute & MixedVariableArguments) {
    $attribute |= RefVariableArguments | VariableArguments;
  }
  if ($cls === null) {
    $attribute |= IsPublic;
  } else if (!($attribute & (IsProtected|IsPrivate|IsPublic))) {
    $attribute |= IsPublic;
  }

  if (isset($func['ref'])) $attribute |= IsReference;
  printf('  (const char *)0x%04X, "%s", "", (const char*)0, '.
         "(const char*)0,\n",
         $attribute, $func['name'], "", 0, 0);

  if (!empty($func['doc'])) {
    printf('  "%s",'."\n", escape_cpp($func['doc']));
  }

  printf("  ");
  printDataType($func['return']);
  foreach ($func['args'] as $arg) {
    $attr = IsNothing;
    if (isset($arg['ref'])) $attr |= IsReference;
    printf('(const char *)0x%04X, "%s", "", ',
           $attr, $arg['name']);
    printDataType($arg['type']);
    if (array_key_exists('value', $arg)) {
      printf('"%s", "%s", ',
             escape_cpp($arg['defaultSerialized']),
             escape_cpp($arg['defaultText']));
    } else {
      printf('"", "", ');
    }
    print("NULL,\n  ");
  }
  print("NULL,\n");
  print("  NULL,\n");
  print("  NULL,\n");
}

function classClassMap($cls) {
  $attribute = (($cls['flags'] &
                 (IsAbstract|IsFinal|HasDocComment|NoDefaultSweep|
                  HipHopSpecific)) |
                IsSystem | IsNothing);

  printf('  (const char *)0x%04X, "%s", "%s", "", (const char *)0, ' .
         "(const char *)0,\n",
         $attribute,
         $cls['name'], strtolower($cls['parent'])); # revert strtolower

  if (!empty($cls['doc'])) {
    printf('  "%s",'."\n", escape_cpp($cls['doc']));
  }

  printf("  ");
  foreach ($cls['ifaces'] as $iface) {
    printf('"%s", ', strtolower($iface));
  }
  printf("NULL,\n");

  foreach ($cls['methods'] as $m) {
    functionClassMap($m, $cls);
  }
  printf("  NULL,\n");

  foreach ($cls['properties'] as $p) {
    $att = $p['flags'] & (IsProtected|IsPrivate|IsPublic|IsStatic);
    $att |= IsNothing;
    if (!($att & (IsProtected|IsPrivate|IsPublic))) $att |= IsPublic;
    printf("  (const char *)0x%04X, \"%s\",\n", $att, $p['name']);
  }
  printf("  NULL,\n");

  foreach ($cls['consts'] as $k) {
    constantClassMap($k, $cls['name']);
  }
  printf("  NULL,\n");

  // no attributes
  printf("  NULL,\n");
}

function constantClassMap($constant, $cls = null) {
  printf('  "%s", ', escape_cpp($constant['name']));
  if (array_key_exists('value', $constant)) {
    $v = serialize($constant['value']);
    printf('(const char*)%d, "%s",'."\n",
           strlen($v), escape_cpp($v));
  } else {
    switch ($constant['name']) {
      case 'STDOUT':
      case 'STDERR':
      case 'STDIN':
        printf("(const char *)&BuiltinFiles::Get%s, nullptr,\n",
               $constant['name']);
        return;
    }
    if ($cls !== null) {
      printf('(const char*)&q_%s$$%s, ',
             $cls, $constant['name']);
      printDataType($constant['type'], 2);
    } else {
      printf("(const char *)&k_%s, ",
             $constant['name']);
      printDataType($constant['type'], 2);
    }
    printf("\n");
  }
}

function printDataType($t, $off = 0) {
  if ($t === null) {
    printf('(const char *)-1, ');
    return;
  }
  switch (typename($t)) {
    case 'bool':   $s = 'KindOfBoolean'; $n = 9; break;
    case 'int':
    case 'int64':  $s = 'KindOfInt64'; $n = 10; break;
    case 'double': $s = 'KindOfDouble'; $n = 11; break;
    case 'String': $s = 'KindOfString'; $n = 20; break;
    case 'Array':  $s = 'KindOfArray'; $n = 32; break;
    case 'Object': $s = 'KindOfObject'; $n = 64; break;
    default:
      if (is_string($t)) {
        $s = 'KindOfObject'; $n = 64;
        break;
      }
      $s = 'KindOfUnknown'; $n = -1;
      break;
  }
  printf('(const char *)0x%x, ', ($n + $off) & 0xffffffff);
}

function write_constants($extern) {
  global $constants;
  foreach ($constants as $constant) {
    if (array_key_exists('value', $constant)) {
      $v = $constant['value'];
      if (is_bool($v)) {
        $type = 'bool';
      } else if (is_int($v)) {
        $type = 'int64_t';
      } else if (is_double($v)) {
        $type = 'double';
      } else if (is_string($v)) {
        $type = 'StaticString';
      } else if (is_null($v)) {
        $type = 'Variant';
      } else {
        throw new Exception("bad value for constant '$constant'");
      }
      if ($extern) {
        printf("extern const %s k_%s;\n",
               $type, $constant['name']);
      } else if (is_string($v)) {
        printf("extern const StaticString k_%s(%s,%d);\n",
               $constant['name'],
               php_escape_val($v), strlen($v));
      } else {
        printf("const %s k_%s = %s;\n",
               $type, $constant['name'], php_escape_val($v));
      }
    }
  }
}
