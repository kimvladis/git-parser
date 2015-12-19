<?php
if ($argc != 3) { ?>
    Скрип парсинга .git

    Использование:
    <?php echo $argv[0]; ?> <url> <folder>

    <url> Ссылка на сайт
    <folder> Папка, в которую необходимо загрузить исходный код.

<?php } else {
    $url = $argv[1];
    $folder = $argv[2];
    $hashes = [];
    $used_hashes = [];
    if (substr($folder, 0, 1) != '/') {
        $folder = getcwd() . DIRECTORY_SEPARATOR . $folder;
    }
    $objects_folder = $folder . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'objects';
    if (file_exists($folder)) {
        die('Такая директория уже существует.');
    }
    mkdir($folder);
    
    /** @var array $folders_in_git стандартная структура директории .git */
    $folders_in_git = [
        '.git' => [
            'objects'        => [
                'info' => [
                    'packs' => 'file',
                ],
                'pack' => [

                ],
            ],
            'branches'       => [],
            'COMMIT_EDITMSG' => 'file',
            'config'         => 'file',
            'description'    => 'file',
            'FETCH_HEAD'     => 'file',
            'HEAD'           => 'file',
            'hooks'          => [],
            'index'          => 'file',
            'info'           => [
                'exclude' => 'file',
                'refs'    => 'file',
            ],
            'logs'           => [
                'HEAD' => 'file',
                'refs' => [
                    'heads'   => [
                        'master' => 'file',
                        'HEAD'   => 'file',
                    ],
                    'remotes' => [
                        'origin' => [
                            'master' => 'file',
                            'HEAD'   => 'file',
                        ],
                    ],
                    'stash'   => 'file',
                ],
            ],
            'ORIG_HEAD'      => 'file',
            'packed-refs'    => 'file',
            'refs'           => [
                'heads'   => [
                    'master' => 'file',
                ],
                'remotes' => [
                    'origin' => [
                        'master' => 'file',
                        'HEAD'   => 'file',
                    ],
                ],
                'stash'   => 'file',
                'tags'    => [],
            ],
        ],
    ];

    /**
     * Функция сохранения объектов в локальном репозитории
     * 
     * @param string $content
     * @param string $file_name
     */
    function saveObjects($content, $file_name = null)
    {
        /** если передали хэш */
        if (strlen($content) == 40) {
            global $folder, $url, $objects_folder, $hashes;
            $web_folder = substr($content, 0, 2);
            $web_file = substr($content, 2, 38);
            $content = file_get_contents("{$url}/.git/objects/{$web_folder}/{$web_file}");
            if ($content !== false) {
                if (!file_exists($objects_folder . DIRECTORY_SEPARATOR . $web_folder)) {
                    mkdir($objects_folder . DIRECTORY_SEPARATOR . $web_folder);
                }
                $object_file = $objects_folder . DIRECTORY_SEPARATOR . $web_folder . DIRECTORY_SEPARATOR . $web_file;
                file_put_contents($object_file, $content);
                $hashes[$web_folder . $web_file] = [
                    'file' => null,
                    'type' => 'commit',
                ];
            } else {
                file_put_contents(getcwd() . DIRECTORY_SEPARATOR . 'problems', $folder . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'objects' . DIRECTORY_SEPARATOR . $web_folder . DIRECTORY_SEPARATOR . $web_file . "\n", FILE_APPEND);
            }
        } else {
            $content = explode(PHP_EOL, $content);
            if ($file_name == 'ORIG_HEAD') {
                foreach ($content as $line) {
                    if ($line > 1) {
                        saveObjects($line, $file_name);
                    }
                }
            }
            if ($file_name == 'HEAD') {
                $content = array_reverse($content);
                foreach ($content as $line) {
                    $line = explode(' ', $line);
                    if (isset($line[1])) {
                        saveObjects($line[1], $file_name);
                    }
                }
            }
        }
    }

    /**
     * Функция рекурсивного обхода исходного дерева директорий
     * 
     * @param $url 
     * @param $root
     * @param $folders
     * @param $web_root
     */
    function createRep($url, $root, $folders, $web_root)
    {
        foreach ($folders as $file_name => $content) {
            if ($content == 'file') {
                $content = file_get_contents("{$url}{$web_root}/{$file_name}");
                if ($content !== false) {
                    file_put_contents($root . DIRECTORY_SEPARATOR . $file_name, $content);
                    saveObjects($content, $file_name);
                } else {
                    file_put_contents(getcwd() . DIRECTORY_SEPARATOR . 'problems', "{$url}{$web_root}/{$file_name}\n", FILE_APPEND);
                }
            } else {
                mkdir($root . DIRECTORY_SEPARATOR . $file_name);
                createRep($url, $root . DIRECTORY_SEPARATOR . $file_name, $content, $web_root . '/' . $file_name);
            }

        }
    }

    createRep($url, $folder, $folders_in_git, '');

    /** обход найденых хэшей */
    while (count($hashes) > 0) {
        foreach ($hashes as $key => $hash) {
            $command = "cd {$folder} && git cat-file -t {$key} 2>&1";
            $obj_type = exec($command);
            $command = "cd {$folder} && git cat-file -p {$key} 2>&1";
            unset($obj_content);
            exec($command, $obj_content, $return_content);

            if ($obj_type == 'commit') {
                foreach ($obj_content as $line) {
                    $line = explode(' ', $line);
                    if ($line[0] == 'parent' || $line[0] == 'tree') {
                        $web_folder = substr($line[1], 0, 2);
                        $web_file = substr($line[1], 2, 38);
                        if (!in_array($web_folder . $web_file, $hashes)) {
                            $content = file_get_contents("{$url}/.git/objects/{$web_folder}/{$web_file}");
                            if ($content !== false) {
                                if (!file_exists($objects_folder . DIRECTORY_SEPARATOR . $web_folder)) {
                                    mkdir($objects_folder . DIRECTORY_SEPARATOR . $web_folder);
                                }
                                $object_file = $objects_folder . DIRECTORY_SEPARATOR . $web_folder . DIRECTORY_SEPARATOR . $web_file;
                                file_put_contents($object_file, $content);
                                if ($line[0] == 'tree') {
                                    if (!in_array($web_folder . $web_file, $used_hashes)) {
                                        $hashes[$web_folder . $web_file] = [
                                            'type' => 'tree',
                                            'file' => '/',
                                        ];
                                    }
                                }
                                if ($line[0] == 'parent') {
                                    if (!in_array($web_folder . $web_file, $used_hashes)) {
                                        $hashes[$web_folder . $web_file] = [
                                            'type' => 'commit',
                                            'file' => null,
                                        ];
                                    }
                                }
                            } else {
                                file_put_contents(getcwd() . DIRECTORY_SEPARATOR . 'problems', $folder . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'objects' . DIRECTORY_SEPARATOR . $web_folder . DIRECTORY_SEPARATOR . $web_file . "\n", FILE_APPEND);
                            }
                        }
                    }
                }
            }
            if ($obj_type == 'tree') {
                if (!file_exists($folder . $hash['file'])) {
                    mkdir($folder . $hash['file']);
                }
                foreach ($obj_content as $line) {
                    $line = explode(' ', $line);
                    $web_folder = substr($line[2], 0, 2);
                    $web_file = substr($line[2], 2, 38);
                    $file_name = substr($line[2], 41);
                    if (!in_array($web_folder . $web_file, $hashes)) {
                        $content = file_get_contents("{$url}/.git/objects/{$web_folder}/{$web_file}");
                        if ($content !== false) {
                            if (!file_exists($objects_folder . DIRECTORY_SEPARATOR . $web_folder)) {
                                mkdir($objects_folder . DIRECTORY_SEPARATOR . $web_folder);
                            }
                            $object_file = $objects_folder . DIRECTORY_SEPARATOR . $web_folder . DIRECTORY_SEPARATOR . $web_file;
                            file_put_contents($object_file, $content);
                            $hash_file_name = $hash['file'] . $file_name;
                            if ($line[1] == 'tree') {
                                $hash_file_name .= DIRECTORY_SEPARATOR;
                            }
                            if (!in_array($web_folder . $web_file, $used_hashes)) {
                                $hashes[$web_folder . $web_file] = [
                                    'file' => $hash_file_name,
                                    'type' => $line[1],
                                ];
                            }
                        } else {
                            file_put_contents(getcwd() . DIRECTORY_SEPARATOR . 'problems', $folder . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'objects' . DIRECTORY_SEPARATOR . $web_folder . DIRECTORY_SEPARATOR . $web_file . "\n", FILE_APPEND);
                        }
                    }
                }
            }
            if ($obj_type == 'blob') {
                if (!file_exists($folder . $hash['file'])) {
                    foreach ($obj_content as $line) {
                        file_put_contents($folder . $hash['file'], $line . "\n", FILE_APPEND);
                    }
                }
            }

            if (in_array($obj_type, ['blob', 'tree', 'commit'])) {
                unset($hashes[$key]);
                $used_hashes[] = $key;
            }
        }
    }
}
