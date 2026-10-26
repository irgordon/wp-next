<?php
/** Versioned site composition. PHP themes remain trusted, operator-deployed source. */
declare( strict_types=1 );
function wp_theme_manifest( string $slug ): ?array
{
    if ( ! preg_match( '/^[a-z0-9_-]+$/D', $slug ) ) {
		return null; 
	}
    $root = WP_ROOT_DIR . '/wp-content/themes';
	$directory = $root . '/' . $slug;
    if ( is_link( $directory ) || ! is_dir( $directory ) || ! str_starts_with( (string) realpath( $directory ), (string) realpath( $root ) . DIRECTORY_SEPARATOR ) ) {
		return null; 
	}
    $path = $directory . '/theme.json';
    if ( ! is_file( $path ) || is_link( $path ) || filesize( $path ) > 32768 ) {
		return null; 
	}
    $data = json_decode( (string) file_get_contents( $path ), true );
    if ( ! is_array( $data ) || ( $data['slug'] ?? '' ) !== $slug || ! is_string( $data['name'] ?? null ) || ! is_string( $data['version'] ?? null ) || ( $data['requires'] ?? '' ) !== '1' || ! is_array( $data['templates'] ?? null ) ) {
		return null; 
	}
    if ( '' === trim($data['name']) || strlen($data['name']) > 120 || '' === trim($data['version']) || strlen($data['version']) > 64 || ($data['tokens'] ?? []) !== ['accent','background','width','font','body_size','spacing'] ) {
		return null;
	}
    foreach ( $data['templates'] as $file ) {
		if ( ! is_string($file) || ! preg_match('/^[a-z0-9_-]+\.php$/D',$file) ) {
			return null;
		}
	}
    foreach ( array_merge( [ 'index.php', 'style.css', 'header.php', 'footer.php', 'page.php' ], array_values( $data['templates'] ) ) as $file ) {
        if ( ! preg_match( '/^[a-z0-9_-]+\.(php|css)$/D', $file ) || is_link( $directory . '/' . $file ) || ! is_file( $directory . '/' . $file ) ) {
			return null; 
		}
    }
    if ( ( $data['templates']['standard'] ?? '' ) !== 'page.php' ) {
		return null; 
	}
    foreach ( array_keys( $data['templates'] ) as $id ) {
		if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,40}$/D', (string) $id ) ) {
			return null; 
		} 
	}
    try {
		foreach ( glob($directory.'/*.php') ?: [] as $source ) {
			if ( is_link($source) || ! str_starts_with( (string)realpath($source), (string)realpath($directory).DIRECTORY_SEPARATOR) ) {
				return null;

			} token_get_all( (string)file_get_contents($source),TOKEN_PARSE);

		} 
	} catch ( Throwable $error ) {
		return null; 
	}
    return $data;
}
function wp_curated_templates(): array
{
    $manifest = wp_theme_manifest( wp_theme_slug() );
	return $manifest['templates'] ?? [ 'standard' => 'page.php' ];
}
function wp_design_live(): array
{
    $stored = json_decode( (string) get_option( 'design_live', '' ), true );
    if ( is_array( $stored ) ) {
		return $stored; 
	}
    return [
		'active_theme'     => (string) get_option( 'active_theme', 'default' ),
		'tokens'           => json_decode( (string) get_option( 'design_tokens', '{}' ), true ) ?: [],
		'homepage_id'      => (int) get_option( 'homepage_id', 0 ),
		'site_logo_id'     => (int) get_option( 'site_logo_id', 0 ),
		'navigation_pages' => json_decode( (string) get_option( 'navigation_pages', '[]' ), true ) ?: [],
		'menus'            => [
			'header' => [],
			'footer' => []
		],
		'sections'         => [
			'header' => '',
			'footer' => ''
		]
	];
}
function wp_design_version(): int
{
	return (int) get_option( 'design_version', 0 ); 
}
function wp_design_menu_parse( string $text ): array
{
    $items = [];
	$previous = 1;
    foreach ( preg_split( '/\r?\n/', trim( $text ) ) ?: [] as $line ) {
        if ( '' === trim( $line ) ) {
			continue; 
		}
        $parts = array_map( 'trim', explode( '|', $line ) );
        if ( 3 !== count( $parts ) || ! preg_match( '/^[1-3]$/D', $parts[2] ) || (int) $parts[2] > $previous + 1 || ( [] === $items && '1' !== $parts[2] ) ) {
			throw new RuntimeException( 'Use Label | address | level (1–3), starting at level 1.' ); 
		}
        $url = $parts[1];
		$parsed = parse_url( $url );
        if ( strlen( $url ) > 2048 || false === $parsed || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) || str_starts_with( $url, '//' ) || ( ! str_starts_with( $url, '/' ) && ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( strtolower( $parsed['scheme'] ?? '' ), [ 'http', 'https' ], true ) || isset( $parsed['user'] ) ) ) ) {
			throw new RuntimeException( 'Navigation addresses must be site-relative or HTTP/S without credentials.' ); 
		}
        $label = sanitize_text_field( $parts[0] );
		if ( '' === $label || strlen( $label ) > 120 || count( $items ) >= 40 ) {
			throw new RuntimeException( 'Use named navigation items, at most 40 per location.' ); 
		}
        $previous = (int) $parts[2];
		$items[] = [
			'label' => $label,
			'url'   => $url,
			'level' => $previous
		];
    }
    return $items;
}
function wp_design_validate( array $config ): array
{
    global $wpdb;
    if ( null === wp_theme_manifest( (string) ( $config['active_theme'] ?? '' ) ) ) {
		throw new RuntimeException( 'Choose a valid installed theme manifest.' ); 
	}
    $tokens = $config['tokens'] ?? [];
    foreach ( [
		'accent'     => '#3858e9',
		'background' => '#ffffff'
	] as $name => $default ) {
        $tokens[ $name ] = $tokens[ $name ] ?? $default;
        if ( ! preg_match( '/^#[a-f0-9]{6}$/iD', $tokens[ $name ] ) ) {
			throw new RuntimeException( 'Choose six-digit colors.' ); 
		}
    }
    if ( ( max( wp_color_luminance( $tokens['accent'] ), wp_color_luminance( $tokens['background'] ) ) + .05 ) / ( min( wp_color_luminance( $tokens['accent'] ), wp_color_luminance( $tokens['background'] ) ) + .05 ) < 4.5 ) {
		throw new RuntimeException( 'Colors need at least 4.5:1 text contrast.' ); 
	}
    foreach ( [
		'width'     => [ 640, 1400, 960 ],
		'body_size' => [ 14, 24, 16 ],
		'spacing'   => [ 12, 80, 32 ]
	] as $name => $bounds ) {
        $value = $tokens[ $name ] ?? $bounds[2];
        if ( false === filter_var( $value, FILTER_VALIDATE_INT ) || (int) $value < $bounds[0] || (int) $value > $bounds[1] ) {
			throw new RuntimeException( 'Choose design values within their displayed limits.' ); 
		}
        $tokens[ $name ] = (int) $value;
    }
    $tokens['font'] = $tokens['font'] ?? 'sans';
	if ( ! in_array( $tokens['font'], [ 'sans', 'serif' ], true ) ) {
		throw new RuntimeException( 'Choose an installed type style.' ); 
	}
    $config['tokens'] = $tokens;
    $config['homepage_id'] = (int) ( $config['homepage_id'] ?? 0 );
	$config['site_logo_id'] = (int) ( $config['site_logo_id'] ?? 0 );
    if ( $config['homepage_id'] > 0 && null === $wpdb->get_row( "SELECT id FROM posts WHERE id=:id AND post_type='page' AND status='published'", [ ':id' => $config['homepage_id'] ] ) ) {
		throw new RuntimeException( 'Choose a published homepage.' ); 
	}
    if ( $config['site_logo_id'] > 0 && null === $wpdb->get_row( "SELECT id FROM media WHERE id=:id AND kind='image' AND state='ready'", [ ':id' => $config['site_logo_id'] ] ) ) {
		throw new RuntimeException( 'Choose a ready image logo.' ); 
	}
    $navigation = $config['navigation_pages'] ?? [];
	if ( ! is_array( $navigation ) || count( $navigation ) > 20 ) {
		throw new RuntimeException( 'Choose at most 20 navigation Pages.' ); 
	}
    foreach ( $navigation as $id ) {
		if ( null === $wpdb->get_row( "SELECT id FROM posts WHERE id=:id AND post_type='page' AND status='published'", [ ':id' => (int) $id ] ) ) {
			throw new RuntimeException( 'Navigation requires published Pages.' ); 
		} 
	}
    $config['navigation_pages'] = array_map( 'intval', $navigation );
    foreach ( [ 'header', 'footer' ] as $location ) {
        $items = $config['menus'][ $location ] ?? [];
        if ( ! is_array( $items ) ) {
			throw new RuntimeException( 'Invalid menu.' ); 
		}
        $text = implode( "\n", array_map( static fn( array $item ): string => (string) ( $item['label'] ?? '' ) . ' | ' . (string) ( $item['url'] ?? '' ) . ' | ' . (string) ( $item['level'] ?? '' ), $items ) );
        $config['menus'][ $location ] = wp_design_menu_parse( $text );
        $raw = (string) ( $config['sections'][ $location ] ?? '' );
		if ( strlen( $raw ) > 40000 ) {
			throw new RuntimeException( 'Shared section exceeds 40 KB.' ); 
		}
        if ( '' !== $raw ) {
			$raw = wp_normalize_content( $raw );
			if ( is_wp_error( $raw ) ) {
				throw new RuntimeException( $raw->get_error_message() ); 
			} 
		}
        $config['sections'][ $location ] = $raw;
    }
    return $config;
}
function wp_design_draft( array $config, int $expected ): int
{
    global $wpdb;
    return $wpdb->transaction( static function ( $db ) use ( $config, $expected ): int {
        if ( ! current_user_can( 'administrator' ) || wp_design_version() !== $expected ) {
			throw new RuntimeException( 'Design changed or permission was revoked. Reload before saving.' ); 
		}
        $config = wp_design_validate( $config );
        $db->query( 'INSERT INTO design_revisions(actor_id,base_version,config) VALUES(:actor,:base,:config)', [
			':base'   => $expected,
			':actor'  => get_current_user_id(),
			':config' => wp_json_encode( $config )
		] );
        return (int) $db->get_row( 'SELECT last_insert_rowid() AS id' )['id'];
    } );
}
function wp_design_override( array $config ): array
{
    return [
		'active_theme'     => $config['active_theme'],
		'design_tokens'    => wp_json_encode( $config['tokens'] ),
		'homepage_id'      => (string) $config['homepage_id'],
		'site_logo_id'     => (string) $config['site_logo_id'],
		'navigation_pages' => wp_json_encode( $config['navigation_pages'] )
	];
}
function wp_design_health( array $config ): void
{
    global $wpdb;
    $runtime = WP_VAR_DIR.'/theme-probe-'.bin2hex(random_bytes(8));
    if ( ! mkdir($runtime,0700,true) ) {
		throw new RuntimeException('Cannot create private theme probe.');
	}
    try {
        $wpdb->query('VACUUM INTO :path',[':path' => $runtime.'/site.sqlite']);
		chmod($runtime.'/site.sqlite',0600);
        wp_design_health_process($config,$runtime);
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ( $iterator as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) {
				rmdir($file->getPathname());

			} else {
				unlink($file->getPathname());

			}
		}rmdir($runtime);
    }
}
function wp_design_health_process( array $config, string $runtime ): void
{
    if ( ! function_exists('proc_open') ) {
		throw new RuntimeException('Theme publication requires PHP CLI process support.'); 
	}
    $command = [ getenv( 'WP_PHP_CLI' ) ?: ( 'cli' === PHP_SAPI ? PHP_BINARY : 'php' ), '-d', 'memory_limit=128M', WP_ROOT_DIR . '/tools/theme-health.php' ];
    $pipes = [];
	$environment = getenv();
	$environment['WP_VAR_DIR'] = $runtime;
	$environment['WP_DB_PATH'] = $runtime.'/site.sqlite';
	$environment['WP_AUTH_SALT'] = wp_auth_salt();
    $process = proc_open( $command, [
		0 => [ 'pipe', 'r' ],
		1 => [ 'pipe', 'w' ],
		2 => [ 'pipe', 'w' ]
	], $pipes, null, $environment );
    if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'PHP CLI health check is unavailable.' ); 
	}
    fwrite( $pipes[0], wp_json_encode( $config ) );
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
    $output = '';
	$errors = '';
	$deadline = microtime( true ) + 5;
    do {
        $output .= stream_get_contents( $pipes[1] );
		$errors .= stream_get_contents( $pipes[2] );
		$status = proc_get_status( $process );
        if ( ! $status['running'] ) {
			break; 
		}
        if ( microtime( true ) > $deadline || strlen( $output ) + strlen( $errors ) > 262144 ) {
			proc_terminate( $process, 9 );
			$status['exitcode'] = -1;
			break; 
		}
        usleep( 10000 );
    } while ( true );
    $output .= stream_get_contents( $pipes[1] );
	$errors .= stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $process );
    if ( 0 !== $status['exitcode'] || ! str_contains( $output, 'THEME_HEALTH_OK' ) || '' !== trim( $errors ) ) {
		throw new RuntimeException( 'Theme health check failed. Live design is unchanged.' ); 
	}
}
function wp_design_fingerprint( string $slug ): string
{
    $parts = [];
    foreach ( glob( WP_ROOT_DIR . '/wp-content/themes/' . $slug . '/*' ) ?: [] as $file ) {
		if ( is_file( $file ) ) {
			$parts[] = basename( $file ) . ':' . hash_file( 'sha256', $file ); 
		} 
	}
    return hash( 'sha256', implode( '|', $parts ) );
}
function wp_design_publish( int $id, int $expected ): void
{
    global $wpdb;
    if ( ! current_user_can( 'administrator' ) ) {
		throw new RuntimeException( 'Design permission required.' ); 
	}
    $row = $wpdb->get_row( 'SELECT * FROM design_revisions WHERE id=:id', [ ':id' => $id ] );
	if ( null === $row ) {
		throw new RuntimeException( 'Design revision not found.' ); 
	}
    $config = wp_design_validate( json_decode( $row['config'], true ) );
	$fingerprint = wp_design_fingerprint( $config['active_theme'] );
	wp_design_health( $config );
    $wpdb->transaction( static function ( $db ) use ( $id, $config, $fingerprint, $expected ): void {
        if ( ! current_user_can( 'administrator' ) || wp_design_version() !== $expected || wp_design_fingerprint( $config['active_theme'] ) !== $fingerprint ) {
			throw new RuntimeException( 'Design or deployed source changed. Reload before publishing.' ); 
		}
        if ( null === $db->get_row('SELECT id FROM design_revisions WHERE id=:id',[':id' => $id]) ) {
			throw new RuntimeException('Design revision was removed. Reload before publishing.');
		}
        $config = wp_design_validate( $config );
        $previous = wp_design_live();
        if ( 0 === (int)get_option('design_live_revision',0) ) {
            $db->query('INSERT INTO design_revisions(actor_id,config,published) VALUES(:actor,:config,1)',[
				':actor'  => get_current_user_id(),
				':config' => wp_json_encode($previous)
			]);
        }
        $db->query('INSERT INTO design_revisions(actor_id,base_version,config,published) VALUES(:actor,:base,:config,1)',[
			':actor'  => get_current_user_id(),
			':base'   => $expected + 1,
			':config' => wp_json_encode($config)
		]);
        $live_id = (int)$db->get_row('SELECT last_insert_rowid() AS id')['id'];
        foreach ( wp_design_override( $config ) + [
			'design_live'          => wp_json_encode( $config ),
            'design_live_revision' => (string)$live_id,
			'design_version'       => (string) ( $expected + 1 )
		] as $key => $value ) {
			$db->query( 'INSERT INTO options(option_name,option_value) VALUES(:key,:value) ON CONFLICT(option_name) DO UPDATE SET option_value=:value', [
				':key'   => $key,
				':value' => $value
			] ); 
		}
        $db->query( 'DELETE FROM design_revisions WHERE id=:id AND published=0', [ ':id' => $id ] );
		wp_audit( 'design_published', $live_id );
    } );
}
function wp_design_preview_init(): void
{
    global $wpdb;
    if ( ! isset( $_GET['design_preview'] ) ) {
		return; 
	}
    $id = (int) wp_input( $_GET, 'design_preview' );
    $row = $wpdb->get_row( 'SELECT * FROM design_revisions WHERE id=:id AND actor_id=:actor', [
		':id'    => $id,
		':actor' => get_current_user_id()
	] );
    if ( ! current_user_can( 'administrator' ) || ! wp_verify_nonce( wp_input( $_GET, '_wpnonce' ), 'design-preview:' . $id ) || null === $row ) {
		wp_die( 'Preview not found.', 'Not found', [ 'response' => 404 ] ); 
	}
    try {
		$config = wp_design_validate( json_decode( $row['config'], true ) );

	} catch ( Throwable $error ) {
		wp_die('Saved preview is unavailable.','Unavailable',['response' => 404]); 
	}
	$GLOBALS['wp_design_override'] = wp_design_override( $config );
	$GLOBALS['wp_design_preview'] = $config;
    header( 'Cache-Control: private, no-store' );
	header( 'X-Robots-Tag: noindex, nofollow' );
}
function wp_design_current(): array
{
	return $GLOBALS['wp_design_preview'] ?? wp_design_live(); 
}
function wp_shared_section( string $location ): void
{
    $config = wp_design_current();
	$raw = $config['sections'][ $location ] ?? '';
	if ( '' !== $raw ) {
		echo '<section class="shared-section">' . wp_render_content( $raw ) . '</section>'; 
	}
}
function wp_design_menu( string $location ): bool
{
    $items = wp_design_current()['menus'][ $location ] ?? [];
	if ( [] === $items ) {
		return false; 
	}
    echo '<ul class="composition-menu">';
	$level = 1;
	$open = false;
    foreach ( $items as $item ) {
        $next = (int) $item['level'];
        if ( $next > $level ) {
			echo '<ul>';

		} else {
			if ( $open ) {
				echo '</li>';

			} while ( $level > $next ) {
				echo '</ul></li>';
				--$level; 
			} 
		}
        $level = $next;
		$open = true;
		echo '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a>';
    }
    if ( $open ) {
		echo '</li>';

	} while ( $level > 1 ) {
		echo '</ul></li>';
		--$level;

	} echo '</ul>';
	return true;
}

/** Retain reference colors when readable; otherwise choose a readable black/white fallback. */
function wp_design_ink( string $background, string $preferred ): string
{
    $ratio = static fn(string $ink):float=>(max(wp_color_luminance($ink),wp_color_luminance($background)) + .05) / (min(wp_color_luminance($ink),wp_color_luminance($background)) + .05);
    if ( $ratio($preferred) >= 4.5 ) {
		return $preferred;
	}
    return $ratio('#000000') >= $ratio('#ffffff') ? '#000000' : '#ffffff';
}
