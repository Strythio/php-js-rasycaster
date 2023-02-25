<?php
define('HOST_NAME',"localhost"); 
define('PORT',"8090");
require_once("class.client.php");


class Vec2f {
    public float $x;
    public float $y;
    public function __construct($x, $y) {
        $this->x = $x;
        $this->y = $y;
    }
    public function __toString() {
        return number_format((float)$this->x, 2, '.', '') . ", " . number_format((float)$this->y, 2, '.', '');
    }
    public function sub(Vec2f $other) {
        return new Vec2f($this->x - $other->x, $this->y - $other->y);
    }
    public function magnitude() {
        return sqrt(pow($this->x, 2) + pow($this->y, 2));
    }
    public function normalized() {
        $mag = $this->magnitude();
        return new Vec2f($this->x / $mag, $this->y / $mag);
    }
}

class Player {
    public Vec2f $pos;
    public Vec2f $dir;
    public function __construct(Vec2f $pos, Vec2f $dir) {
        $this->pos = $pos;
        $this->dir = $dir;
    }
 
}

class Ray {
    public Vec2f $start;
    public Vec2f $end;

    public function __construct() {
        $this->start = new Vec2f(0.0, 0.0);
        $this->end = new Vec2f(0.0, 0.0);
    }
    public function __toString() {
        return "Start: " . $this->start . ", End: " . $this->end;
    }
}

class World {
    public Player $player;
    public $map;
    public $worldSize = 6;
    public $rays;
    public $intersections;


    public function __construct($worldSize) {
        $this->worldSize = $worldSize;
        $this->map = array_fill(0, $this->worldSize, array_fill(0, $this->worldSize, 0));
        $this->player = new Player(new Vec2f(0.0, 0.0), (new Vec2f(-2.0, 1.4))->normalized());
        $this->rays = array();
    }
    public function castRays() {
        $this->rays = array_diff($this->rays, $this->rays);
        for ($a = 0; $a < 360; $a++) {
            $angle = (float)$a * 0.017453; // to radians
            $dir = $this->player->dir = (new Vec2f(sin($angle), cos($angle)))->normalized();

            $ray = new Ray();

            $ray->start = $this->player->pos;

            $posX = $this->player->pos->x;
            $posY = $this->player->pos->y;

            $rayDirX = $this->player->dir->x;
            $rayDirY = $this->player->dir->y;

            $mapX = floor($posX);
            $mapY = floor($posY);

            $sideDistX = null;
            $sideDistY = null;

            $deltaDistX = ($rayDirX == 0) ? 1e30 : abs(1 / $rayDirX);
            $deltaDistY = ($rayDirY == 0) ? 1e30 : abs(1 / $rayDirY);
            $perpWallDist = null;

            $stepX = null;
            $stepY = null;

            $hit = 0;
            $side = null;
            
            if ($rayDirX < 0) {
                $stepX = -1;
                $sideDistX = ($posX - $mapX) * $deltaDistX;
            } else {
                $stepX = 1;
                $sideDistX = ($mapX + 1.0 - $posX) * $deltaDistX;
            } if ($rayDirY < 0) {
                $stepY = -1;
                $sideDistY = ($posY - $mapY) * $deltaDistY;
            } else {
                $stepY = 1;
                $sideDistY = ($mapY + 1.0 - $posY) * $deltaDistY;
            }

            while ($hit == 0) {
                if ($sideDistX < $sideDistY) {
                    $sideDistX += $deltaDistX;
                    $mapX += $stepX;
                    $side = 0;
                }
                else {
                    $sideDistY += $deltaDistY;
                    $mapY += $stepY;
                    $side = 1;
                }
                if ($mapX >= $this->worldSize || $mapY >= $this->worldSize || $mapX < 0 || $mapY < 0) {
                    break;
                }
                //Check if ray has hit a wall
                if ($this->map[$mapX][$mapY] > 0) {
                    break;
                }
            }
            if($side == 0) {
                $perpWallDist = ($sideDistX - $deltaDistX);
            } else {
                $perpWallDist = ($sideDistY - $deltaDistY);
            }
            $ray->end = new Vec2f($dir->x * $perpWallDist, $dir->y * $perpWallDist);
            array_push($this->rays, clone $ray);
        }
    }
}


$world = new World(6);

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($server, HOST_NAME, PORT);
socket_listen($server);

$client = new Client($server);

$worldSize = 16;
$world = new World($worldSize);
$world->player->pos->x = 7.5;
$world->player->pos->y = 5.5;
$angle = M_PI * 2;
while (true) {
    if (!$client->isConnected) {
        $client->accept();
    }
    if ($client->receive($messages)) {
        foreach ($messages as $message) {
            $x = $message->cellClicked->x;
            $y = $message->cellClicked->y;
            $cell = &$world->map[$x][$y];
            if ($cell == 0) {
                $cell = 1;
            } else {
                $cell = 0;
            }
        }
    }
    //$world->player->dir = (new Vec2f(sin($angle), cos($angle)))->normalized();
    $world->castRays();
    //$angle += 0.1;
    $client->send(json_encode($world));

    usleep(100000);
}


?>