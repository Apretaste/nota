<?php

class Chat extends Service
{
	/**
	 * Get the list of conversations, or post a note
	 *
	 * @author salvipascual
	 * @param Request
	 * @return Response
	 */
	public function _main(Request $request)
	{
		// Connecting to database
		$connection = new Connection();

		//
		// SHOW THE LIST OF OPEN CHATS WHEN SUBJECT=NOTA
		//
		if (empty($request->query))
		{
			$union = "(SELECT B.username, MAX(send_date) as sent
				FROM _note A RIGHT JOIN person B
				ON A.to_user = B.email
				WHERE from_user = '{$request->email}'
				AND NOT EXISTS (SELECT id FROM relations WHERE user1 = '{$request->email}' AND user2 = A.to_user AND type = 'blocked' AND confirmed = 1)
				GROUP BY to_user)
				UNION
				(SELECT B.username, MAX(send_date) as sent
				FROM _note A RIGHT JOIN person B
				ON A.from_user = B.email
				WHERE to_user = '{$request->email}'
				AND NOT EXISTS (SELECT id FROM relations WHERE user1 = '{$request->email}' AND user2 = A.from_user AND type = 'blocked' AND confirmed = 1)
				GROUP BY from_user)";

			// Searching contacts of the current user
			$sql = "SELECT username, MAX(sent) as sent FROM ($union) U GROUP BY username ORDER BY sent DESC;";

			$notes = $connection->query($sql);
			foreach($notes as $k => $note)
			{
				$notes[$k]->profile = $this->utils->getPerson($this->utils->getEmailFromUsername($note->username));
				$last_note = $this->getConversation($request->email, $notes[$k]->profile->email, 1);
				if(empty($last_note)) continue;
				$notes[$k]->last_note = array(
					'from' => $last_note[0]->username,
					'note' => $last_note[0]->text,
					'date' => $last_note[0]->sent);
			}

			// Return the response
			$response = new Response();
			$response->setResponseSubject("Lista de chats abiertos");
			$response->createFromTemplate("open.tpl", array("notes" => $notes));
			return $response;
		}

		// check that the username of the note is valid
		$argument = explode(" ", $request->query);
		$friendUsername = str_replace("@", "", $argument[0]);
		$find = $connection->query("SELECT email FROM person WHERE username = '$friendUsername';");
		if (empty($find))
		{
			$response = new Response();
			$response->setResponseSubject("El usuario @$friendUsername no existe");
			$response->createFromTemplate("user_not_exists.tpl", array("username"=>$friendUsername));
			return $response;
		}
		$friendEmail = $find[0]->email;

		//
		// GET A LIST OF THE CHATS WHEN SUBJECT=NOTA @USERNAME
		//
		if(count($argument) == 1)
		{
			return $this->_get($request, $friendEmail);
		}

		// get text of the the note to post
		unset($argument[0]);
		$note = implode(" ", $argument);

		// if you are trying to post using the example text, send the help document
		if ($note == 'Reemplace este texto por su nota')
		{
			$response = new Response();
			$response->setResponseSubject("No reemplazaste el texto por tu nota");
			$response->createFromText("Para enviar una nota escriba la palabra CHAT seguida del nombre de usuario del destinatario y luego el texto de la nota a enviar, todo en el asunto del correo. Por ejemplo: CHAT @pepe1 Hola pepe");
			return $response;
		}

		//
		// POST A NOTE WHEN SUBJECT=NOTA @username MY NOTE HERE
		//
		return $this->_post($request, $friendUsername, $friendEmail, $note);
	}

	/**
	 * Get latest chats after certain NOTE_ID
	 *
	 * @api
	 * @author salvipascual
	 * @param Request
	 * @return Response
	 * */
	public function _get(Request $request, $friendEmail=false)
	{
		$connection = new Connection();
		$response = new Response();

		// get the username and ID of the query
		$argument = explode(" ", $request->query);
		$friendUsername = str_replace("@", "", $argument[0]);
		$lastID = isset($argument[1]) ? $argument[1] : 0;

		// get the friend email if not passed
		if(empty($friendEmail))
		{
			$friendEmail = $this->utils->getEmailFromUsername($friendUsername);
			if ( ! $friendEmail) return $response->createFromJSON('{"code":"ERROR", "message":"Wrong username"}');
		}

		// get the array of notes
		$notes = $this->getConversation($request->email, $friendEmail, $lastID);

		// get the new last ID and remove ID for each note
		$newLastID = 0;
		foreach ($notes as $nota)
		{
			if($nota->id > $newLastID) $newLastID = $nota->id;
		}

		// get your username
		$yourUsername = $this->utils->getUsernameFromEmail($request->email);

		// prepare the details for the view
		$responseContent = array(
			"code" => "ok",
			"last_id" => $newLastID,
			"friendUsername" => $friendUsername,
			"chats" => $notes
		);

		// Send the response email to your friend
		$response->setResponseSubject("Nueva nota de @$yourUsername");
		$response->createFromTemplate("chats.tpl", $responseContent);
		return $response;
	}

	/**
	 * Create a new chat without sending any emails, useful for the API
	 *
	 * @api
	 * @author salvipascual
	 * @param Request
	 * @return Response
	 * */
	public function _post(Request $request, $friendUsername=false, $friendEmail=false, $note=false)
	{
		$response = new Response();

		// load params if not passed
		if(empty($friendUsername) || empty($friendEmail) || empty($note))
		{
			// get the friend username
			$argument = explode(" ", $request->query);
			$friendUsername = str_replace("@", "", $argument[0]);

			// get the friend email
			$friendEmail = $this->utils->getEmailFromUsername($friendUsername);
			if (empty($friendEmail)) return $response->createFromText("El nombre de usuario @$friendUsername no parece existir. Verifica que sea correcto e intenta nuevamente.", "ERROR", "Wrong username");

			// get the text for the note
			unset($argument[0]);
			$note = implode(" ", $argument);
			if(empty($note)) return $response->createFromText("No has pasado un texto, no podemos enviar una nota en blanco. El asunto debe ser: NOTA @username TEXTO A ENVIAR", "ERROR", "No text to save");
		}

		// store the note in the database
		$connection = new Connection();
		$connection->query("INSERT INTO _note (from_user, to_user, `text`) VALUES ('{$request->email}','$friendEmail','$note');");

		// send push notification for users of Piropazo
		$pushNotification = new PushNotification();
		$appid = $pushNotification->getAppId($friendEmail, "piropazo");
		if($appid)
		{
			$personFrom = $this->utils->getPerson($request->email);
			$personTo = $this->utils->getPerson($friendEmail);
			$pushNotification->piropazoChatPush($appid, $personFrom, $personTo, $note);
			return $response;
		}

		// send web notification for users of Pizarra
		$appid = $pushNotification->getAppId($friendEmail, "pizarra");
		if($appid)
		{
			$yourUsername = $this->utils->getUsernameFromEmail($request->email);
			$pushNotification->pizarraChatReceived($appid, $yourUsername, $note);
			return $response;
		}

		// send emails for users within the email platform
		$notes = $this->getConversation($request->email, $friendEmail);
		$yourUsername = $this->utils->getUsernameFromEmail($request->email);
		$responseContent = array("friendUsername" => $yourUsername, "chats" => $notes);
		$this->utils->addNotification($friendEmail, "nota", "@$yourUsername le ha enviado una nota", "NOTA @$yourUsername");

		// Send the response email to your friend
		$response->setResponseEmail($friendEmail);
		$response->setResponseSubject("Nueva nota de @$yourUsername");
		$response->createFromTemplate("chats.tpl", $responseContent);
		return $response;
	}

	/**
	 * Return the count of all unread notes. Useful for the API
	 *
	 * @api
	 * @author salvipascual
	 * @param Request
	 * @return Response
	 */
	public function _unread(Request $request)
	{
		// get count of unread notes
		$connection = new Connection();
		$notes = $connection->query("
			SELECT B.username, MAX(send_date) as sent, COUNT(B.username) as counter
			FROM _note A LEFT JOIN person B
			ON A.from_user = B.email
			WHERE to_user = '{$request->email}'
			AND NOT EXISTS (SELECT id FROM relations WHERE user1 = '{$request->email}' AND user2 = A.from_user AND type = 'blocked' AND confirmed = 1)
			AND read_date IS NULL
			GROUP BY B.username
			ORDER BY sent DESC;");

		// get the total counter
		$total = 0;
		foreach ($notes as $k => $note)
		{
			$total += $note->counter;
			$notes[$k]->profile = $this->utils->getPerson($this->utils->getEmailFromUsername($note->username));
			$notes[$k]->last_note = $this->getConversation($request->email, $notes[$k]->profile->email, 1);
		}

		// respond back to the API
		$response = new Response();
		$jsonResponse = array("code" => "ok", "total"=>$total, "items" => $notes);
		return $response->createFromJSON(json_encode($jsonResponse));
	}

	/**
	 * Return a list of notes between $email1 & $email2
	 *
	 * @author salvipascual
	 * @param String $email1
	 * @param String $email2
	 * @param String $lastID, get all from this ID
	 * @param string $limit, integer number of max rows
	 * @return array
	 */
	private function getConversation($yourEmail, $friendEmail, $lastID=0, $limit=20)
	{
		// if a last ID is passed, do not cut the result based on the limit
		$setLimit = ($lastID > 0) ? "" : "LIMIT $limit";

		// retrieve conversation between users
		$connection = new Connection();
		$notes = $connection->query("
			SELECT * FROM (
				SELECT A.id, B.username, A.text, A.send_date as sent, A.read_date as `read`
				FROM _note A LEFT JOIN person B
				ON A.from_user = B.email
				WHERE from_user = '$yourEmail' AND to_user = '$friendEmail'
				AND A.id > '$lastID'
				UNION
				SELECT A.id, B.username, A.text, A.send_date as sent, CURRENT_TIMESTAMP as `read`
				FROM _note A LEFT JOIN person B
				ON A.from_user = B.email
				WHERE from_user = '$friendEmail' AND to_user = '$yourEmail'
				AND A.id > '$lastID') C
			ORDER BY sent DESC $setLimit");

		// mark the other person notes as unread
		if($notes)
		{
			$lastNoteID = end($notes)->id;
			$connection->query("
				UPDATE _note
				SET read_date = CURRENT_TIMESTAMP
				WHERE read_date is NULL
				AND from_user = '$friendEmail'
				AND id >= $lastNoteID");
		}

		return $notes;
	}
}
