<?php

// Exit if accessed directly.
defined('ABSPATH') || exit;

// // For "fake" pages -- Check themes first, fallback to plugin
if ($query_var = get_query_var('custom_page')) {
  echo view()->first([$query_var, "$query_var"])->render();
  return;
}
