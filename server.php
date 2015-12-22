<?php
class G
{
    static $users = array();
    static $files = array();
    static $watchList = array();
    static $inotify;
}

$server = new swoole_websocket_server("0.0.0.0", 9502, SWOOLE_BASE);

$server->on('WorkerStart', function($server, $worker_id) {
    G::$inotify = inotify_init();
    swoole_event_add(G::$inotify, function ($ifd) use ($server) {
        $events = inotify_read(G::$inotify);
        if ($events)
        {
            foreach ($events as $event)
            {
                $filename = G::$watchList[$event['wd']];
                $line = fgets(G::$files[$filename]['fp']);
                if ($line)
                {
                    foreach (G::$files[$filename]['users'] as $fd)
                    {
                        $server->push($fd, $line);
                    }
                }
                else
                {
                    echo "EOF\n";
                }
            }
        }
    });
});

$server->set(array(
    'log_file' => '/tmp/jack.txt'
));

$server->on('Open', function ($server, $req)
{
    echo "connection open: " . $req->fd;
});

$server->on('Message', function (swoole_websocket_server $server, $frame)
{
    echo "message: " . $frame->data;
    $result = json_decode($frame->data, true);
    $filename = $result['filename']; //以文件名作为数组的值，Key是fd

    $filename = __DIR__.'/'.$filename;
    if (!is_file($filename))
    {
        $server->push($frame->fd, "file[$filename] is not exist.\n");
        return;
    }

    //用户监听的文件
    G::$users[$frame->fd]['watch_file'] = $filename;
    //文件被哪些人监听了
    G::$files[$filename]['users'][] = $frame->fd;

    //还没有创建inotify句柄
    if (empty(G::$files[$filename]['inotify_fd']))
    {
        //添加监听
        $wd = inotify_add_watch(G::$inotify, $filename, IN_MODIFY);
        $fp = fopen($filename, 'r');
        clearstatcache();
        $filesizelatest = filesize($filename);
        fseek($fp, $filesizelatest);

        G::$watchList[$wd] = $filename;
        G::$files[$filename]['inotify_fd'] = $wd;
        G::$files[$filename]['fp'] = $fp;

        //清理掉其他文件的监听
        foreach(G::$files as $f => $v)
        {
            if ($f != $filename)
            {
                $k = array_search($frame->fd, G::$files[$f]['users']);
                unset(G::$files[$f]['users'][$k]);
            }
        }
    }
});

$server->on('Close', function ($server, $fd)
{
    echo "connection close: " . $fd;
});

$server->start();


