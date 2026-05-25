<?php
/**
 * Execute callbacks for the CDCF MCP abilities.
 *
 * Each ability's execute_callback resolves to one of the named functions
 * below. Where a `cdcf/v1` REST endpoint already encodes the desired
 * behaviour (creation + auto-translation + relationship linking), the
 * callback dispatches to it internally via rest_do_request() so we reuse
 * the exact sanitisation, validation and translation-queue logic rather
 * than duplicating it. Operations with no existing endpoint (delete,
 * content edits, media sideload, listings, plain page/post creation) are
 * implemented directly with core WordPress functions.
 *
 * Callbacks receive a single associative `$input` array (already shaped
 * by the ability's JSON input_schema) and return either plain data
 * (array) or a WP_Error on failure.
 */

if (defined('ABSPATH') === false) {
    return;
}

/**
 * Percent-encode colons inside in-page fragment hrefs (href="#…:…") so they
 * survive wp_kses_post().
 *
 * WordPress core's wp_kses_bad_protocol() reads the substring before the first
 * ":" in an href as a URL scheme. For a footnote anchor like
 * href="#fn:encyclical" it sees scheme "fn" (the leading "#" is normalized
 * away), finds it's not an allowed protocol, and strips it — leaving
 * href="encyclical". This silently breaks every named-anchor footnote /
 * back-link produced by Markdown converters that use the fn:/fnref: convention
 * (Python-Markdown, PHP Markdown Extra, …). Numeric anchors (#fn1) have no
 * colon and are unaffected, which is why pandoc-generated content never hit it.
 *
 * Encoding the colon to %3A in the href only (the matching id="fn:encyclical"
 * keeps its literal colon — kses doesn't protocol-check id values) means kses
 * sees no scheme delimiter and leaves the href intact, while the browser
 * percent-decodes the fragment back to "fn:encyclical" when matching it to the
 * id (HTML fragment navigation decodes before id comparison), so both the
 * footnote links and the back-links still resolve. Non-fragment hrefs (real
 * URLs) and colon-free fragments are left untouched; already-encoded %3A is a
 * no-op, so this is idempotent.
 */
function cdcf_mcp_protect_fragment_anchors(string $html): string {
    $out = preg_replace_callback(
        '/\bhref\s*=\s*(["\'])(#[^"\']*)\1/i',
        static function (array $m): string {
            return 'href=' . $m[1] . str_replace(':', '%3A', $m[2]) . $m[1];
        },
        $html
    );
    // preg_replace_callback returns null only on PCRE error; fall back to the
    // original content rather than nulling it out.
    return $out ?? $html;
}

/**
 * Dispatch an internal REST request against a registered cdcf/v1 route.
 *
 * Runs in-process as the current user, so the route's own
 * permission_callback still applies on top of the ability's.
 *
 * @return mixed|WP_Error Decoded response data, or WP_Error on failure.
 */
function cdcf_mcp_dispatch(string $method, string $route, array $params = []) {
    $request = new WP_REST_Request($method, $route);
    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }
    $response = rest_do_request($request);
    if ($response->is_error()) {
        return $response->as_error();
    }
    return $response->get_data();
}

/**
 * Shared helper: create a team_member via the existing /team-member
 * endpoint, forcing a specific council.
 */
function cdcf_mcp_create_member(array $input, string $council) {
    $params = ['council' => $council];
    $passthrough = [
        'title', 'content', 'member_title', 'member_role',
        'member_linkedin_url', 'member_github_url', 'featured_image_id',
        'collab_post_id',
    ];
    foreach ($passthrough as $key) {
        if (isset($input[$key]) && $input[$key] !== '') {
            $params[$key] = $input[$key];
        }
    }
    return cdcf_mcp_dispatch('POST', '/cdcf/v1/team-member', $params);
}

function cdcf_mcp_cb_create_board_member(array $input) {
    return cdcf_mcp_create_member($input, 'team_members');
}

function cdcf_mcp_cb_create_ecclesial_council_member(array $input) {
    return cdcf_mcp_create_member($input, 'ecclesial_council');
}

function cdcf_mcp_cb_create_technical_council_member(array $input) {
    return cdcf_mcp_create_member($input, 'technical_council');
}

/**
 * Academic liaison == a member of an academic collaboration's governance
 * (academic_council). Requires the English academic collaboration post id.
 */
function cdcf_mcp_cb_create_academic_liaison(array $input) {
    if (empty($input['collab_post_id'])) {
        return new WP_Error(
            'missing_collab_post_id',
            'collab_post_id (the English academic collaboration post id) is required for an academic liaison.',
            ['status' => 400]
        );
    }
    return cdcf_mcp_create_member($input, 'academic_council');
}

/**
 * Author profile == a council-less team_member. Dispatches to /team-member
 * with NO council param, so the endpoint creates + auto-translates the post
 * but skips all About-page relationship linking (its `if (!$council)` path).
 * The member is then wired up out-of-band: linked to a WordPress user's
 * `author_team_member` profile field in wp-admin, or added as a project lead
 * via cdcf/add-project-lead. Cannot reuse cdcf_mcp_create_member(), which
 * always forces a council.
 */
function cdcf_mcp_cb_create_author_member(array $input) {
    return cdcf_mcp_dispatch_create('/cdcf/v1/team-member', $input, [
        'title', 'content', 'member_title', 'member_role',
        'member_linkedin_url', 'member_github_url', 'featured_image_id',
    ]);
}

/**
 * Forward the non-empty whitelisted fields of $input to a cdcf/v1 create
 * endpoint. Shared by the Community-page domain creators, which all reuse
 * the endpoint's auto-translation + page-linking verbatim.
 */
function cdcf_mcp_dispatch_create(string $route, array $input, array $passthrough) {
    $params = [];
    foreach ($passthrough as $key) {
        if (isset($input[$key]) && $input[$key] !== '') {
            $params[$key] = $input[$key];
        }
    }
    return cdcf_mcp_dispatch('POST', $route, $params);
}

function cdcf_mcp_cb_create_academic_collaboration(array $input) {
    return cdcf_mcp_dispatch_create('/cdcf/v1/academic-collaboration', $input, [
        'title', 'collab_description', 'collab_university', 'collab_department',
        'collab_location', 'collab_website_url', 'featured_image_id',
    ]);
}

function cdcf_mcp_cb_create_community_channel(array $input) {
    return cdcf_mcp_dispatch_create('/cdcf/v1/community-channel', $input, [
        'title', 'channel_description', 'channel_url', 'channel_icon',
    ]);
}

function cdcf_mcp_cb_create_local_group(array $input) {
    return cdcf_mcp_dispatch_create('/cdcf/v1/local-group', $input, [
        'title', 'group_description', 'group_url', 'group_location',
    ]);
}

/**
 * Shared content updater for member bios / project descriptions.
 * Optionally re-enqueues translations of the edited source post.
 */
function cdcf_mcp_update_content(int $post_id, string $expected_type, array $input) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== $expected_type) {
        return new WP_Error('invalid_post', "No {$expected_type} post found for id {$post_id}.", ['status' => 404]);
    }

    $update = ['ID' => $post_id];
    if (array_key_exists('content', $input)) {
        $update['post_content'] = wp_kses_post(cdcf_mcp_protect_fragment_anchors((string) $input['content']));
    }
    if (!empty($input['title'])) {
        $update['post_title'] = sanitize_text_field($input['title']);
    }
    if (!empty($input['excerpt'])) {
        $update['post_excerpt'] = sanitize_text_field($input['excerpt']);
    }

    $result = wp_update_post($update, true);
    if (is_wp_error($result)) {
        return $result;
    }

    $requeued = [];
    if (!empty($input['retranslate'])
        && function_exists('pll_get_post_translations')
        && function_exists('cdcf_enqueue_translation')
    ) {
        foreach (pll_get_post_translations($post_id) as $lang => $tid) {
            if ($lang === 'en' || (int) $tid === $post_id) {
                continue;
            }
            cdcf_enqueue_translation($tid, $post_id, $lang);
            $requeued[$lang] = (int) $tid;
        }
    }

    return ['success' => true, 'post_id' => $post_id, 'retranslated' => $requeued];
}

function cdcf_mcp_cb_update_member_bio(array $input) {
    $post_id = absint($input['post_id'] ?? 0);
    $result  = cdcf_mcp_update_content($post_id, 'team_member', $input);
    if (is_wp_error($result)) {
        return $result;
    }
    if (function_exists('update_field')) {
        foreach (['member_title', 'member_role', 'member_linkedin_url', 'member_github_url'] as $field) {
            if (isset($input[$field]) && $input[$field] !== '') {
                update_field($field, sanitize_text_field($input[$field]), $post_id);
            }
        }
    }
    return $result;
}

function cdcf_mcp_cb_update_project_description(array $input) {
    return cdcf_mcp_update_content(absint($input['post_id'] ?? 0), 'project', $input);
}

/**
 * Trash (or, with force=true, permanently delete) a team_member and all
 * of its Polylang translations.
 */
function cdcf_mcp_cb_delete_member(array $input) {
    $post_id = absint($input['post_id'] ?? 0);
    $post    = get_post($post_id);
    if (!$post || $post->post_type !== 'team_member') {
        return new WP_Error('invalid_post', "No team_member post found for id {$post_id}.", ['status' => 404]);
    }

    $force = !empty($input['force']);

    $ids = [$post_id];
    if (function_exists('pll_get_post_translations')) {
        $ids = array_values(pll_get_post_translations($post_id));
        if (!in_array($post_id, $ids, true)) {
            $ids[] = $post_id;
        }
    }

    $deleted = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        $ok = $force ? wp_delete_post($id, true) : wp_trash_post($id);
        if ($ok) {
            $deleted[] = $id;
        }
    }

    return ['success' => true, 'deleted' => $deleted, 'forced' => $force];
}

/**
 * Replace an ACF relationship field's value (delegates to /relationship).
 * Use for setting an About-page council, a collaboration's governance, etc.
 */
function cdcf_mcp_cb_update_member_relationship(array $input) {
    return cdcf_mcp_dispatch('POST', '/cdcf/v1/relationship', [
        'post_id' => absint($input['post_id'] ?? 0),
        'field'   => sanitize_text_field($input['field'] ?? ''),
        'value'   => array_map('absint', (array) ($input['value'] ?? [])),
    ]);
}

/**
 * Append one or more team members to a project's project_leads field
 * (read-modify-write so existing leads are preserved).
 */
function cdcf_mcp_cb_add_project_lead(array $input) {
    $project_id = absint($input['project_id'] ?? 0);
    $member_ids = array_values(array_filter(array_map('absint', (array) ($input['member_ids'] ?? []))));
    if (!$project_id || !$member_ids) {
        return new WP_Error('invalid_input', 'project_id and a non-empty member_ids array are required.', ['status' => 400]);
    }

    $current = cdcf_mcp_dispatch('GET', '/cdcf/v1/relationship', [
        'post_id' => $project_id,
        'field'   => 'project_leads',
    ]);
    if (is_wp_error($current)) {
        return $current;
    }

    $value = array_map('absint', (array) ($current['value'] ?? []));
    foreach ($member_ids as $member_id) {
        if (!in_array($member_id, $value, true)) {
            $value[] = $member_id;
        }
    }

    return cdcf_mcp_dispatch('POST', '/cdcf/v1/relationship', [
        'post_id' => $project_id,
        'field'   => 'project_leads',
        'value'   => array_values($value),
    ]);
}

function cdcf_mcp_cb_update_project_status(array $input) {
    return cdcf_mcp_dispatch('POST', '/cdcf/v1/project-status', [
        'post_id' => absint($input['post_id'] ?? 0),
        'status'  => sanitize_text_field($input['status'] ?? ''),
    ]);
}

/**
 * Set a project's repository / website URLs across all its translations.
 */
function cdcf_mcp_cb_set_project_repos(array $input) {
    $project_id = absint($input['project_id'] ?? 0);
    $post       = get_post($project_id);
    if (!$post || $post->post_type !== 'project') {
        return new WP_Error('invalid_post', "No project post found for id {$project_id}.", ['status' => 404]);
    }
    if (!function_exists('update_field')) {
        return new WP_Error('acf_missing', 'ACF is not active.', ['status' => 500]);
    }

    $fields = [];
    if (isset($input['project_repo_url'])) {
        $fields['project_repo_url'] = esc_url_raw($input['project_repo_url']);
    }
    if (isset($input['project_url'])) {
        $fields['project_url'] = esc_url_raw($input['project_url']);
    }
    if (!$fields) {
        return new WP_Error('invalid_input', 'Provide project_repo_url and/or project_url.', ['status' => 400]);
    }

    $post_ids = [$project_id];
    if (function_exists('pll_get_post_translations')) {
        $post_ids = array_values(pll_get_post_translations($project_id));
        if (!in_array($project_id, $post_ids, true)) {
            $post_ids[] = $project_id;
        }
    }

    foreach ($post_ids as $pid) {
        foreach ($fields as $name => $val) {
            update_field($name, $val, (int) $pid);
        }
    }

    return ['success' => true, 'project_id' => $project_id, 'updated_posts' => array_map('intval', $post_ids), 'fields' => $fields];
}

/**
 * Set an existing attachment as a post's featured image. Works for any
 * post type (team_member, project, acad_collab, post, page, ...).
 */
function cdcf_mcp_cb_set_featured_image(array $input) {
    $post_id       = absint($input['post_id'] ?? 0);
    $attachment_id = absint($input['attachment_id'] ?? 0);
    if (!get_post($post_id)) {
        return new WP_Error('invalid_post', "No post found for id {$post_id}.", ['status' => 404]);
    }
    if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
        return new WP_Error('invalid_attachment', "id {$attachment_id} is not an attachment.", ['status' => 400]);
    }
    set_post_thumbnail($post_id, $attachment_id);
    return ['success' => true, 'post_id' => $post_id, 'attachment_id' => $attachment_id];
}

// NOTE: cdcf_mcp_cb_upload_media() was removed along with the cdcf/upload-media
// ability. It sideloaded an agent-supplied URL via download_url(), an SSRF
// vector with no simple safe guard. See includes/abilities.php and the
// security review in docs/wordpress-mcp-evaluation.md.

/**
 * Shared listing helper. Defaults to surfacing not-yet-published items
 * (drafts/pending) so freshly submitted entries are visible.
 */
function cdcf_mcp_list_posts(string $post_type, array $input) {
    $status = $input['post_status'] ?? ['publish', 'draft', 'pending'];
    $status = is_string($status)
        ? sanitize_key($status)
        : array_map('sanitize_key', (array) $status);

    $posts = get_posts([
        'post_type'   => $post_type,
        'post_status' => $status,
        'numberposts' => min(100, max(1, absint($input['limit'] ?? 20))),
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);

    $out = [];
    foreach ($posts as $p) {
        $row = [
            'ID'     => (int) $p->ID,
            'title'  => $p->post_title,
            'status' => $p->post_status,
            'date'   => $p->post_date,
        ];
        if (function_exists('pll_get_post_language')) {
            $row['language'] = pll_get_post_language($p->ID, 'slug');
        }
        $out[] = $row;
    }
    return $out;
}

function cdcf_mcp_cb_list_submitted_projects(array $input) {
    return cdcf_mcp_list_posts('project', $input);
}

function cdcf_mcp_cb_list_submitted_community_projects(array $input) {
    return cdcf_mcp_list_posts('community_project', $input);
}

/**
 * Shared creator for plain page/post drafts.
 */
function cdcf_mcp_create_simple(string $post_type, array $input) {
    $title = sanitize_text_field($input['title'] ?? '');
    if ($title === '') {
        return new WP_Error('missing_title', 'title is required.', ['status' => 400]);
    }

    $allowed_status = ['draft', 'pending', 'publish', 'private'];
    $status = $input['status'] ?? 'draft';
    if (!in_array($status, $allowed_status, true)) {
        $status = 'draft';
    }

    $postarr = [
        'post_type'    => $post_type,
        'post_status'  => $status,
        'post_title'   => $title,
        'post_content' => isset($input['content'])
            ? wp_kses_post(cdcf_mcp_protect_fragment_anchors((string) $input['content']))
            : '',
    ];
    if (!empty($input['excerpt'])) {
        $postarr['post_excerpt'] = sanitize_text_field($input['excerpt']);
    }

    $post_id = wp_insert_post($postarr, true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }

    if ($post_type === 'page' && !empty($input['template'])) {
        update_post_meta($post_id, '_wp_page_template', sanitize_text_field($input['template']));
    }
    if (!empty($input['featured_image_id'])) {
        set_post_thumbnail($post_id, absint($input['featured_image_id']));
    }
    if (function_exists('pll_set_post_language')) {
        pll_set_post_language($post_id, sanitize_key($input['language'] ?? 'en'));
    }

    return [
        'success'  => true,
        'post_id'  => (int) $post_id,
        'status'   => get_post_status($post_id),
        'edit_url' => get_edit_post_link($post_id, 'raw'),
    ];
}

function cdcf_mcp_cb_create_page(array $input) {
    return cdcf_mcp_create_simple('page', $input);
}

function cdcf_mcp_cb_create_post(array $input) {
    return cdcf_mcp_create_simple('post', $input);
}

/**
 * Provision a low-privilege WordPress user via /cdcf/v1/create-user. The
 * endpoint enforces the role allowlist (author/contributor/subscriber) and
 * the cdcf_create_limited_users capability gate; this callback only forwards
 * the whitelisted, non-empty fields.
 */
function cdcf_mcp_cb_create_user(array $input) {
    return cdcf_mcp_dispatch_create('/cdcf/v1/create-user', $input, [
        'username', 'email', 'role', 'display_name', 'first_name', 'last_name',
    ]);
}
