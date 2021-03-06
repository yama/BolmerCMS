<?php namespace Bolmer;

class Parser
{
    /**
     * @var array $_eval_stack стек вызовов плагинов/сниппетов
     */
    protected $_eval_stack = array();
    /**
     * @var null|string $_eval_type тип обрабатываемого на данный момент элемента (Snippet или Plugin)
     */
    protected $_eval_type = null;
    /**
     * @var null|string $_eval_name имя обрабатываемого на данный момент элемента (имя сниппета или плагина)
     */
    protected $_eval_name = null;
    /**
     * @var null|string $_eval_hash подпись обрабатываемого на данный момент
     */
    protected $_eval_hash = null;

    /** @var \Pimple $_inj коллекция зависимостей */
    private $_inj = null;

    /** @var \Bolmer\Core $_core */
    protected $_core = null;

    /**
     * @param \Pimple $inj коллекция зависимостей
     */
    public function __construct(\Pimple $inj)
    {
        $this->_inj = $inj;
        $this->_core = $inj['core'];
    }

    /**
     * Сохранение типа и имени выполняемого объекта
     * После выполнения кода вызывается метод unregisterEvalInfo().
     *
     * @param string $type тип выполняемого объекта (Snippet или Plugin)
     * @param string $name имя выполняемого объекта (имя сниппета или плагина)
     * @return null|string уникальный идентификатор выполняемого объекта устанавливаемй в методе addToEvalStack() дебагера
     */
    public function registerEvalInfo($type, $name)
    {
        $hash = $this->_inj['debug']->addToEvalStack($type, $name);
        $this->_inj['debug']->setDataEvalStack($hash, 'owner', $this->_eval_hash);
        $this->_eval_stack[] = array('type' => $this->_eval_type, 'name' => $this->_eval_name, 'hash' => $this->_eval_hash);
        $this->_eval_type = $type;
        $this->_eval_name = $name;
        $this->_eval_hash = $hash;
        if ($this->_eval_type == 'snippet') {
            $this->_core->currentSnippet = $this->_eval_name;
        }
        return $hash;
    }

    /**
     * Общая информация о выполняемом на текущий момент объекте
     * @return array массив данных о выполняемом на текущий момент объекте
     */
    public function getCurrentEval()
    {
        return array('type' => $this->_eval_type, 'name' => $this->_eval_name, 'hash' => $this->_eval_hash);
    }

    /**
     * Удаление из стека объектов выполненного объекта
     *
     * @param float $time время выполнения объекта
     * @return bool этот метод всегда возвращает true
     */
    public function unregisterEvalInfo($time = 0.0)
    {
        $this->_inj['debug']->setDataEvalStack($this->_eval_hash, 'time', $time);
        $tmp = array_pop($this->_eval_stack);
        $this->_core->currentSnippet = null;
        if (is_array($tmp)) {
            $this->_eval_type = $tmp['type'];
            $this->_eval_name = $tmp['name'];
            $this->_eval_hash = $tmp['hash'];
            if ($this->_eval_type == 'snippet') {
                $this->_core->currentSnippet = $this->_eval_name;
            }
        } else {
            $this->_eval_name = $this->_eval_type = $this->_eval_hash = null;
        }
        return true;
    }

    /**
     * Подстановка значений плейсхолдеров и ТВ параметров документа
     * Пример плейсхолдера: [*placeholder_name*]
     *
     * @param string $content текст в котором следует произвести замену
     * @return string обработаная строка
     */
    public function mergeDocumentContent($content)
    {
        if (strpos($content, '[*') === false)
            return $content;
        $replace = array();
        $matches = $this->getTagsFromContent($content, '[*', '*]');
        if ($matches) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                if ($matches[1][$i]) {
                    $key = $matches[1][$i];
                    $key = substr($key, 0, 1) == '#' ? substr($key, 1) : $key; // remove # for QuickEdit format
                    $value = $this->_core->documentObject[$key];
                    if (is_array($value)) {
                        include_once BOLMER_MANAGER_PATH . 'includes/tmplvars.format.inc.php';
                        include_once BOLMER_MANAGER_PATH . 'includes/tmplvars.commands.inc.php';
                        $value = getTVDisplayFormat($value[0], $value[1], $value[2], $value[3], $value[4]);
                    }
                    $replace[$i] = $value;
                }
            }
            $content = str_replace($matches[0], $replace, $content);
        }
        return $content;
    }

    /**
     * Подстановка значений системных настроек
     * Пример плейсхолдера: [(config_name)]
     *
     * @param string $content текст в котором следует произвести замену
     * @return string обработаная строка
     */
    public function mergeSettingsContent($content)
    {
        if (strpos($content, '[(') === false)
            return $content;
        $replace = array();
        $matches = $this->getTagsFromContent($content, '[(', ')]');
        if ($matches) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                if ($matches[1][$i] && array_key_exists($matches[1][$i], $this->_core->config))
                    $replace[$i] = $this->_core->getConfig($matches[1][$i]);
            }

            $content = str_replace($matches[0], $replace, $content);
        }
        return $content;
    }

    /**
     * Подстановка содержимого чанков в текст
     * Пример плейсхолдера: {{chunk_name}}
     *
     * @param string $content текст в котором следует произвести замену
     * @return string обработаная строка
     */
    public function mergeChunkContent($content)
    {
        if (strpos($content, '{{') === false)
            return $content;
        $replace = array();
        $matches = $this->getTagsFromContent($content, '{{', '}}');
        if ($matches) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                if ($matches[1][$i]) {
                    if (isset($this->_core->chunkCache[$matches[1][$i]])) {
                        $replace[$i] = $this->_core->chunkCache[$matches[1][$i]];
                    } else {
                        $row = \Bolmer\Model\BChunk::filter('getItem', $matches[1][$i]);
                        $this->_core->chunkCache[$matches[1][$i]] = $replace[$i] = $row->snippet;
                    }
                }
            }
            $content = str_replace($matches[0], $replace, $content);
            $content = $this->mergeSettingsContent($content);
        }
        return $content;
    }

    /**
     * Подстановка значений плейсхолдеров в текст
     * Пример плейсхолдера: [+placeholder_name+]
     *
     * @param string $content текст в котором следует произвести замену
     * @return string обработаная строка
     */
    public function mergePlaceholderContent($content)
    {
        if (strpos($content, '[+') === false)
            return $content;
        $replace = array();
        $content = $this->mergeSettingsContent($content);
        $matches = $this->getTagsFromContent($content, '[+', '+]');
        if ($matches) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $v = '';
                $key = $matches[1][$i];
                if ($key && is_array($this->_core->placeholders) && array_key_exists($key, $this->_core->placeholders))
                    $v = $this->_core->placeholders[$key];
                if ($v === '')
                    unset($matches[0][$i]); // here we'll leave empty placeholders for last.
                else
                    $replace[$i] = $v;
            }
            $content = str_replace($matches[0], $replace, $content);
        }
        return $content;
    }

    /**
     * Перевод строки параметров в ассоциативный массив параметров
     * Пример строки:
     *      &nameA=Имя параметра A;text;Значение параметра A &nameB=Имя параметра B;list;Вариант 1,Вариант 2,Вариант 3;Вариант 2
     * Пример массива на выходе:
     *      array(
     *          'nameA' => 'Значение параметра А',
     *          'nameB' => 'Вариант 2'
     *      )
     *
     * @param string $propertyString разбираемая стркока параметров
     * @return array ассоциативный массив с параметрами array('имя параметра' => 'значение')
     */
    public function parseProperties($propertyString)
    {
        $parameter = array();
        if (!empty ($propertyString)) {
            $tmpParams = explode("&", $propertyString);
            for ($x = 0; $x < count($tmpParams); $x++) {
                if (strpos($tmpParams[$x], '=', 0)) {
                    $pTmp = explode("=", $tmpParams[$x]);
                    $pvTmp = explode(";", trim($pTmp[1]));
                    if ($pvTmp[1] == 'list' && $pvTmp[3] != "")
                        $parameter[trim($pTmp[0])] = $pvTmp[3]; //list default
                    else
                        if ($pvTmp[1] != 'list' && $pvTmp[2] != "")
                            $parameter[trim($pTmp[0])] = $pvTmp[2];
                }
            }
        }
        return $parameter;
    }

    /**
     * Поиск Bolmer|MODX тегов в тексте
     *
     * @param $content разбираемая строка
     * @param string $left префикс тега
     * @param string $right постфикс тега
     * @return array массив обнаруженых тегов
     */
    public function getTagsFromContent($content, $left = '[+', $right = '+]')
    {
        $hash = explode($left, $content);
        foreach ($hash as $i => $v) {
            if (0 < $i) $hash[$i] = $left . $v;
        }

        $i = 0;
        $count = count($hash);
        $safecount = 0;
        $temp_hash = array();
        while (0 < $count) {
            $open = 1;
            $close = 0;
            $safecount++;
            if (1000 < $safecount) break;
            while ($close < $open && 0 < $count) {
                $safecount++;
                if (!isset($temp_hash[$i])) $temp_hash[$i] = '';
                if (1000 < $safecount) break;
                $remain = array_shift($hash);
                $remain = explode($right, $remain);
                foreach ($remain as $v) {
                    if ($close < $open) {
                        $close++;
                        $temp_hash[$i] .= $v . $right;
                    } else break;
                }
                $count = count($hash);
                if (0 < $i && strpos($temp_hash[$i], $right) === false) $open++;
            }
            $i++;
        }
        $matches = array();
        $i = 0;
        foreach ($temp_hash as $v) {
            if (strpos($v, $left) !== false) {
                $v = substr($v, 0, strrpos($v, $right));
                $matches[0][$i] = $v . $right;
                $matches[1][$i] = substr($v, strlen($left));
                $i++;
            }
        }
        return $matches;
    }

    /**
     * Returns the chunk content for the given chunk name
     *
     * @param string $chunkName
     * @return boolean|string
     */
    public function getChunk($chunkName)
    {
        return isset($this->_core->chunkCache[$chunkName]) ? $this->_core->chunkCache[$chunkName] : null;
    }

    /**
     * parseText
     * @version 1.0 (2013-10-17)
     *
     * @desc Replaces placeholders in text with required values.
     *
     * @param string $chunk - String to parse. @required
     * @param array $chunkArr - Array of values. Key — placeholder name, value — value. @required
     * @param string $prefix - Placeholders prefix. Default: '[+'.
     * @param string $suffix  - Placeholders suffix. Default: '+]'.
     *
     * @return string - Parsed text.
     */
    public function parseText($chunk, $chunkArr, $prefix = '[+', $suffix = '+]')
    {
        if (!is_array($chunkArr)) {
            return $chunk;
        }

        foreach ($chunkArr as $key => $value) {
            $chunk = str_replace($prefix . $key . $suffix, $value, $chunk);
        }

        return $chunk;
    }

    /**
     * parseChunk
     * @version 1.1 (2013-10-17)
     *
     * @desc Replaces placeholders in a chunk with required values.
     *
     * @param string $chunkName Name of chunk to parse. @required
     * @param array $chunkArr Array of values. Key — placeholder name, value — value. @required
     * @param string $prefix Placeholders prefix. Default: '{'.
     * @param string $suffix Placeholders suffix. Default: '}'.
     *
     * @return string|bool Parsed chunk or false if $chunkArr is not array.
     */
    public function parseChunk($chunkName, $chunkArr, $prefix = '{', $suffix = '}')
    {
        //TODO: Wouldn't it be more practical to return the contents of a chunk instead of false?
        if (!is_array($chunkArr)) {
            return false;
        }

        return $this->parseText($this->getChunk($chunkName), $chunkArr, $prefix, $suffix);
    }

    /**
     * Получение всех плейсхолдеров зарегистрированных на странице на текущий момент
     *
     * @return array массив плейсхолдеров
     */
    public function getPlaceholders()
    {
        return is_array($this->_core->placeholders) ? $this->_core->placeholders : array();
    }

    /**
     * Returns the placeholder value
     *
     * @param string $name Placeholder name
     * @return string Placeholder value
     */
    public function getPlaceholder($name)
    {
        return (is_array($this->_core->placeholders) && isset($this->_core->placeholders[$name])) ? $this->_core->placeholders[$name] : null;
    }

    /**
     * Sets a value for a placeholder
     *
     * @param string $name The name of the placeholder
     * @param string $value The value of the placeholder
     * @return string
     */
    public function setPlaceholder($name, $value)
    {
        return $this->_core->placeholders[$name] = $value;
    }

    /**
     * Set placeholders en masse via an array or object.
     *
     * @param object|array $subject
     * @param string $prefix
     */
    public function toPlaceholders($subject, $prefix = '')
    {
        if (is_object($subject)) {
            $subject = get_object_vars($subject);
        }
        if (is_array($subject)) {
            foreach ($subject as $key => $value) {
                $this->toPlaceholder($key, $value, $prefix);
            }
        }
    }

    /**
     * For use by toPlaceholders(); For setting an array or object element as placeholder.
     *
     * @param string $key
     * @param object|array $value
     * @param string $prefix
     */
    public function toPlaceholder($key, $value, $prefix = '')
    {
        if (is_array($value) || is_object($value)) {
            $this->toPlaceholders($value, "{$prefix}{$key}.");
        } else {
            $this->setPlaceholder("{$prefix}{$key}", $value);
        }
    }

    /**
     * Parse a source string.
     *
     * Handles most MODX tags. Exceptions include:
     *   - uncached snippet tags [!...!]
     *   - URL tags [~...~]
     *
     * @param string $source
     * @param bool $uncached_snippets
     * @return string
     */
    public function parseDocumentSource($source, $uncached_snippets = false)
    {
        // set the number of times we are to parse the document source
        $this->_core->minParserPasses = empty ($this->_core->minParserPasses) ? 2 : $this->_core->minParserPasses;
        $this->_core->maxParserPasses = empty ($this->_core->maxParserPasses) ? 10 : $this->_core->maxParserPasses;
        $passes = $this->_core->minParserPasses;
        for ($i = 0; $i < $passes; $i++) {
            // get source length if this is the final pass
            if ($i == ($passes - 1))
                $st = strlen($source);
            if ($this->_core->dumpSnippets == 1) {
                $this->_core->snippetsCode .= "<fieldset><legend><b style='color: #821517;'>PARSE PASS " . ($i + 1) . "</b></legend><p>The following snippets (if any) were parsed during this pass.</p>";
            }

            // invoke OnParseDocument event
            $this->_core->documentOutput = $source; // store source code so plugins can
            $this->_core->invokeEvent("OnParseDocument"); // work on it via $modx->documentOutput
            $source = $this->_core->documentOutput;

            $source = $this->mergeSettingsContent($source);

            // combine template and document variables
            $source = $this->mergeDocumentContent($source);
            // replace settings referenced in document
            $source = $this->mergeSettingsContent($source);
            // replace HTMLSnippets in document
            $source = $this->mergeChunkContent($source);
            // insert META tags & keywords
            if ($this->_core->getConfig('show_meta') == 1) {
                $source = $this->_core->mergeDocumentMETATags($source);
            }
            if ($uncached_snippets) {
                $source = str_replace(array('[!', '!]'), array('[[', ']]'), $source);
            }
            // find and merge snippets
            $source = $this->mergeSnippetsContent($source);
            // find and replace Placeholders (must be parsed last) - Added by Raymond
            $source = $this->mergePlaceholderContent($source);

            $source = $this->mergeSettingsContent($source);

            if ($this->_core->dumpSnippets == 1) {
                $this->_core->snippetsCode .= "</fieldset><br />";
            }
            if ($i == ($passes - 1) && $i < ($this->_core->maxParserPasses - 1)) {
                // check if source length was changed
                $et = strlen($source);
                if ($st != $et)
                    $passes++; // if content change then increase passes because
            } // we have not yet reached maxParserPasses
        }
        return $source;
    }

    /**
     * Вызов сниппетов указанных в обрабатываемом тексте
     * Пример плейсхолдеров:
     *      [[snippet_name]]
     *      [!snippet_name!]
     *
     * @param string $content текст в котором следует произвести замену
     * @return string обработаная строка
     */
    public function mergeSnippetsContent($content)
    {
        return $this->_inj['snippet']->evalSnippets($content);
    }

    /**
     * Подстановка ссылок на документы Bolmer|MODX
     * Пример плейсхолдера: [~id_документа~]
     *
     * @param string $documentSource текст в котором следует произвести замену
     * @return string обработаная строка
     */
    public function rewriteUrls($documentSource)
    {
        // rewrite the urls
        if ($this->_core->getConfig('friendly_urls') == 1) {
            $aliases = array();
            /* foreach ($this->aliasListing as $item) {
                $aliases[$item['id']]= (strlen($item['path']) > 0 ? $item['path'] . '/' : '') . $item['alias'];
                $isfolder[$item['id']]= $item['isfolder'];
            } */
            foreach ($this->_core->documentListing as $key => $val) {
                $aliases[$val] = $key;
                $isfolder[$val] = $this->_core->aliasListing[$val]['isfolder'];
            }
            $in = '!\[\~([0-9]+)\~\]!ise'; // Use preg_replace with /e to make it evaluate PHP
            $isfriendly = ($this->_core->getConfig('friendly_alias_urls') == 1 ? 1 : 0);
            $pref = $this->_core->getConfig('friendly_url_prefix');
            $suff = $this->_core->getConfig('friendly_url_suffix');
            $thealias = '$aliases[\\1]';
            $thefolder = '$isfolder[\\1]';
            if ($this->_core->getConfig('seostrict') == '1') {

                $found_friendlyurl = "\$this->toAlias(\$this->makeFriendlyURL('$pref','$suff',$thealias,$thefolder,'\\1'))";
            } else {
                $found_friendlyurl = "\$this->makeFriendlyURL('$pref','$suff',$thealias,$thefolder,'\\1')";
            }
            $not_found_friendlyurl = "\$this->makeFriendlyURL('$pref','$suff','" . '\\1' . "')";
            $out = "({$isfriendly} && isset({$thealias}) ? {$found_friendlyurl} : {$not_found_friendlyurl})";
            $documentSource = preg_replace($in, $out, $documentSource);

        } else {
            $in = '!\[\~([0-9]+)\~\]!is';
            $out = "index.php?id=" . '\1';
            $documentSource = preg_replace($in, $out, $documentSource);
        }

        return $documentSource;
    }

    /**
     * Trailing Slash для ссылок на документы контейнеры
     *
     * @param string $pre префикс для автоматической подстановки к алиасу документа
     * @param string $suff суффикс для автоматической подстановки к алиасу документа
     * @param string $alias алиас документа
     * @param int $isfolder является ли документ контейнером
     * @param int $id ID документа
     * @return string обработанная ссылка
     */
    public function makeFriendlyURL($pre, $suff, $alias, $isfolder = 0, $id = 0)
    {
        if ($id == $this->_core->getConfig('site_start') && $this->_core->getConfig('seostrict') === '1') {
            return '/';
        }
        $Alias = explode('/', $alias);
        $alias = array_pop($Alias);
        $dir = implode('/', $Alias);
        unset($Alias);
        if ($this->_core->getConfig('make_folders') === '1' && $isfolder == 1) $suff = '/';
        return ($dir != '' ? "$dir/" : '') . $pre . $alias . $suff;
    }

    /**
     * Формирование ссылки с корректым расширением имени файла
     *
     * @param string $text ссылка
     * @return string обработанная ссылка
     */
    public function toAlias($text)
    {
        $suff = $this->_core->getConfig('friendly_url_suffix');
        return str_replace(array('.xml' . $suff, '.rss' . $suff, '.js' . $suff, '.css' . $suff), array('.xml', '.rss', '.js', '.css'), $text);
    }
}