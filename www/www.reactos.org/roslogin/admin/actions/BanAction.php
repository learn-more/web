<?php
/*
 * PROJECT:     RosLogin - A simple Self-Service and Single-Sign-On around an LDAP user directory
 * LICENSE:     AGPL-3.0-or-later (https://spdx.org/licenses/AGPL-3.0-or-later)
 * PURPOSE:     Banning a user
 * COPYRIGHT:   Copyright 2020 Mark Jansen (mark.jansen@reactos.org)
 */

	class BanAction
	{
		public function perform($ra)
		{
			if (!array_key_exists('username', $_POST))
			{
				throw new RuntimeException("Necessary information not specified");
			}

			$username = $_POST['username'];
			$data = [
				'username' => $username,
			];

			try
			{
				$userinfo = $ra->getUserWithGroupInformation($username);

				if ($userinfo['banned'])
				{
					redirect_to("?p=user&already_banned=1&" . http_build_query($data));
				}

				$ra->banUser($username, $userinfo);
				redirect_to("?p=user&banned=1&" . http_build_query($data));
			}
			catch (InvalidUserNameException $e)
			{
				// Unknown user, back to home!
				redirect_to("?p=home&invalid_username=1&" . http_build_query($data));
			}
		}
	}
