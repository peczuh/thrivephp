<?
	namespace ThriveData\ThrivePHP;
	
	class ACL
	{
		const none = 0;
		const select = 1;
		const update = 2;
		const insert = 4;
		const delete = 8;
		const manager = 16;
		const admin = 32; // 32768 + 16384
		
		static function session($user_id)
		{
			$json = DB::query(
				"WITH t_user AS (
					SELECT id FROM users WHERE id=$1
				),
				t_acl AS (
					WITH t AS(
						SELECT
							k.id AS key,
							bit_or(p.permissions) AS permissions
						FROM
							acl_permissions AS p
							JOIN acl_keys AS k ON (p.key_id = k.id)
							JOIN roles AS r ON (p.role_id = r.id)
							JOIN users_roles AS ur ON (ur.role_id = r.id)
							JOIN users AS u ON (ur.user_id = u.id)
						WHERE u.id=(SELECT id FROM t_user)
						GROUP BY k.id
					)
					SELECT json_object_agg(t.key, t.permissions ORDER BY t.key) AS data FROM t
				),
				t_roles AS (
					SELECT json_object_agg(r.id, true) AS data
					FROM users AS u
						JOIN users_roles AS ur ON (ur.user_id = u.id)
						JOIN roles AS r ON (ur.role_id = r.id)
					WHERE u.id = (SELECT id FROM t_user)
				)
				SELECT json_build_object(
					'roles', (SELECT data FROM t_roles),
					'acl', (SELECT data FROM t_acl)
				) AS data",
				$user_id
			)->single(json: 'array')->data;
			return $json;
		}
	}
	
?>