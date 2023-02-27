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
    public function add(Vec2f $other) {
        return new Vec2f($this->x + $other->x, $this->y + $other->y);
    }
    public function magnitude() {
        return sqrt(pow($this->x, 2) + pow($this->y, 2));
    }
    public function normalized() {
        $mag = $this->magnitude();
        return $this->scaled(1.0 / $mag);
    }
    public function scaled($factor) {
        return new Vec2f($this->x * $factor, $this->y * $factor);
    }
    public function rotated($angle) {
        $cs = cos($angle);
        $sn = sin($angle);
        return new Vec2f($this->x * $cs - $this->y * $sn, $this->x * $sn + $this->y * $cs);
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

    public $length = 0;
    public $side = 0;
    public $angle = 0;

    public int $cellHit = 0;

    public function __construct() {
        $this->start = new Vec2f(0.0, 0.0);
        $this->end = new Vec2f(0.0, 0.0);
    }
    public function __toString() {
        return "Start: " . $this->start . ", End: " . $this->end;
    }
}

class ClientScreen {
    public int $width = 0;
    public int $height = 0;
}

class World {
    public Player $player;
    public $map;
    public $worldSize = 6;
    public $rays;


    public function __construct($worldSize) {
        $this->worldSize = $worldSize;
        $this->map = array_fill(0, $this->worldSize, array_fill(0, $this->worldSize, 0));
        $this->player = new Player(new Vec2f(0.0, 0.0), (new Vec2f(-2.0, 1.4))->normalized());
        $this->rays = array();
    }
    public function castRays($screen, $fov) {
        $this->rays = array_diff($this->rays, $this->rays);
        $posX = $this->player->pos->x;
        $posY = $this->player->pos->y;
        for ($x = 0; $x < $screen->width; $x++) {
            $angle = (((($x / $screen->width) * 2) - 1) * ($fov / 2.0)) * 0.017453; // -fov/2 to fov/2 deg in radians
            $dir = $this->player->dir->rotated($angle);
            
            $ray = new Ray();

            $ray->start = $this->player->pos;

            $rayDirX = $dir->x;
            $rayDirY = $dir->y;

            $mapX = floor($posX);
            $mapY = floor($posY);

            $sideDistX = null;
            $sideDistY = null;

            $deltaDistX = ($dir->x == 0) ? 1e30 : abs(1 / $dir->x);
            $deltaDistY = ($dir->y== 0) ? 1e30 : abs(1 / $dir->y);
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
                    $hit = $this->map[$mapX][$mapY];
                    break;
                }
            }

            if($side == 0) {
                $perpWallDist = ($sideDistX - $deltaDistX);
            } else {
                $perpWallDist = ($sideDistY - $deltaDistY);
            }
            $ray->end = new Vec2f($dir->x * $perpWallDist, $dir->y * $perpWallDist);
            $ray->length = $perpWallDist;
            $ray->side = $side;
            $ray->angle = $angle;
            $ray->cellHit = $hit;
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

$worldSize = 25;
$world = new World($worldSize);
$world->player->pos->x = 7.5;
$world->player->pos->y = 5.5;
$angle = M_PI * 2;


$screenInfo = null;
$fov = 90;
while (true) {
    if (!$client->isConnected) {
        $client->accept();
        $screenInfo = null;
        $client->send(json_encode($world));
    }
    if ($client->receive($messages)) {
        foreach ($messages as $message) {
            if (property_exists($message, "cellClicked")) {
                $x = $message->cellClicked->x;
                $y = $message->cellClicked->y;
                $cell = &$world->map[$x][$y];
                if ($cell == 0) {
                    $cell = 1;
                } else {
                    $cell = 0;
                }
            } else if (property_exists($message, "screenSize")) {
                echo "Received screen size information\n";
                $screenInfo = $message->screenSize;
            } else if (property_exists($message, "movePlayerTo")) {
                $world->player->pos->x = $message->movePlayerTo->x;
                $world->player->pos->y = $message->movePlayerTo->y;
            } else if (property_exists($message, "movePlayerBy")) {
                $world->player->pos = $world->player->pos->add($world->player->dir->scaled($message->movePlayerBy));
            } else if (property_exists($message, "rotatePlayer")) {
                $world->player->dir = $world->player->dir->rotated($message->rotatePlayer * 0.017453);
            } else if (property_exists($message, "changeFOV")) {
                $fov = $message->changeFOV;
            }
        }
    }

    if ($screenInfo === null) continue;
    $world->castRays($screenInfo, $fov);
    $client->send(json_encode($world));

}


?>