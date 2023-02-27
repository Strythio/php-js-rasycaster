<?php
class Client {
	private $server;
	private Socket $clientSocket;
	private string $ip_address;
	public bool $isConnected = false;
	public function __construct(Socket $sock) {
		$this->server = $sock;
	}
	public function accept() {
		echo "Waiting for connection\n";
		$this->clientSocket = socket_accept($this->server);
		echo "Connection Accepted\n";
		echo "Reading header...\n";		
		$header = socket_read($this->clientSocket, 1024);
		echo "Performing handshake...\n";
		if (!$this->doHandshake($header, $this->clientSocket, HOST_NAME, PORT)) {
			echo "Handshake failed!\n";
			return;
		}
		echo "Getting client IP address... ";
		socket_getpeername($this->clientSocket, $ip_address);
		$this->ip_address = $ip_address;
		echo $this->ip_address;
		echo "\n";
		$this->isConnected = true;
	}
	public function send($message) {
		if (!$this->isConnected) {
			return false;
		}
		$encodedMessage = $this->seal(json_encode(($message)));
		$messageLength = strlen($encodedMessage);
		@socket_write($this->clientSocket, $encodedMessage, $messageLength);
		return true;
	}
	public function receive(&$output) {
		if (!$this->isConnected) {
			return false;
		}
		// $sockets = array($this->clientSocket);
		// $s = socket_select($sockets, $w, $e, 0);
		// if ($s === false || $s === 0) {
		// 	return false;
		// }
		$buf = socket_read($this->clientSocket, 4096, PHP_BINARY_READ);
		if ($buf !== false) {
			$bytes = strlen($buf);
			if ($bytes == 0) {
				$this->disconnect();
				return false;
			}
			$unsealed = $this->unseal($buf);
			if ($bytes == 8 && ord($unsealed) == 3) {
				// This condition seems to indicate client disconnect
				$this->disconnect();
				return false;
			}
			echo "Read $bytes bytes from socket_recv(): $unsealed.\n";
			$output = json_decode($unsealed);
			if ($output == null) {
				echo "cannot decode message\n";
				return false;
			}
			return true;
		} else {
			echo "socket_recv() failed; reason: " . socket_strerror(socket_last_error($this->clientSocket)) . "\n";
			$this->disconnect();
			return false;
		}
	}

	private function disconnect() {
		socket_close($this->clientSocket);
		unset($this->clientSocket);
		$this->isConnected = false;
		$this->ip_address = "";
		echo "Client disconnected\n";
	}

	private function unseal($socketData) {
		$length = ord($socketData[1]) & 127;
		if($length == 126) {
			$masks = substr($socketData, 4, 4);
			$data = substr($socketData, 8);
		}
		elseif($length == 127) {
			$masks = substr($socketData, 10, 4);
			$data = substr($socketData, 14);
		}
		else {
			$masks = substr($socketData, 2, 4);
			$data = substr($socketData, 6);
		}
		$socketData = "";
		for ($i = 0; $i < strlen($data); ++$i) {
			$socketData .= $data[$i] ^ $masks[$i%4];
		}
		return $socketData;
	}

	private function seal($socketData) {
		$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($socketData);
		
		if($length <= 125)
			$header = pack('CC', $b1, $length);
		elseif($length > 125 && $length < 65536)
			$header = pack('CCn', $b1, 126, $length);
		elseif($length >= 65536) {
			$upper_32_bits = $length >> 32;
			$lower_32_bits = $length & 0xffffffff;
			$header = pack('CCNN', $b1, 127, $upper_32_bits, $lower_32_bits);
		}
		return $header.$socketData;
	}

	private function doHandshake($received_header,$client_socket_resource, $host_name, $port) {
		$headers = array();
		$lines = preg_split("/\r\n/", $received_header);
		foreach($lines as $line)
		{
			$line = chop($line);
			if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
			{
				$headers[$matches[1]] = $matches[2];
			}
		}

		if (!array_key_exists('Sec-WebSocket-Key', $headers)) {
			$this->disconnect();
			return false;
		}
		$secKey = $headers['Sec-WebSocket-Key'];
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		$buffer  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
		"Upgrade: websocket\r\n" .
		"Connection: Upgrade\r\n" .
		"WebSocket-Origin: $host_name\r\n" .
		"WebSocket-Location: ws://$host_name:$port/demo/shout.php\r\n".
		"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
		socket_write($client_socket_resource,$buffer,strlen($buffer));
		return true;
	}
}
?>