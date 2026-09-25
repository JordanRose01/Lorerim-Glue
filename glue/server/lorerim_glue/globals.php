<?php
// LoreRim Glue - earliest hook (main.php:54). Load the library and mark our state
// messages as "fast" so they never wait on the LLM semaphore.
// lrg_initiative and lrg_scenetalk are NOT listed: they are LLM requests. A dropped initiative tick still costs
// nothing, because preprocessing.php (main.php:193) ends it before the MAIN semaphore (main.php:243).
require_once __DIR__ . '/lib/lrg_core.php';

// [0.4.0] lrg_topics / lrg_dlg join the list: Phase 2's state messages are fast too (one list read must
// never wait on the LLM semaphore). lrg_dlgtalk is NOT listed - it is the module's one LLM request.
$GLOBALS['external_fast_commands'] = array_values(array_unique(array_merge($GLOBALS['external_fast_commands'] ?? [], ['lrg_npcstate', 'lrg_scene', 'lrg_log', 'lrg_topics', 'lrg_dlg'])));

// Another intimacy plugin with a different consent model must not run alongside this one.
if (is_dir(dirname(__DIR__) . '/aiagent_nsfw') && !defined('LRG_SHARMAT_PRESENT')) {
    define('LRG_SHARMAT_PRESENT', true);
}

// [0.5.2 / PT9] CHIM ships the CLIENT half of its own audio filter (tts/audiofilterd_client.php) and
// calls it for every PocketTTS line; the daemon half was never shipped in this distro, so every spoken
// line logs "Audio processing failed for PocketTTS response" and keeps its 0.25-0.31 s of leading
// silence. We supply the missing half on the socket CHIM already calls - CHIM's own files unchanged.
// Cost here in the steady state: one filemtime() of a marker file. It never throws (see the hook).
require_once __DIR__ . '/tts/lrg_audiofilterd_boot.php';
lrgTtsEnsureAudiofilterd();
