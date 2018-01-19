<?php
/*
Plugin Name: Links Shortcode
Plugin URI: http://www.apprique.com/wordpress-plugins
Description: Displays all links of a certain category in a post using a shortcode, according to a definable template. Includes optional Facebook Like button.
Version: 1.8.1
Author: Maarten Swemmer
Author URI: http://blog.bigcircle.nl
*/

require_once(ABSPATH . WPINC . '/formatting.php');
global $linkssc_default_template;
$linkssc_default_template = "<div itemscope itemtype=\"http://schema.org/Rating\" class=\"links_sc_fb\">
[optional [date]: ||]<a itemprop=\"url\" href=\"[link_url]\" target=\"_blank\" ><span itemprop=\"name\">[link_name]</span></a>
<meta itemprop=\"worstRating\" content=\"1\"><meta itemprop=\"bestRating\" content=\"5\"><meta itemprop=\"ratingValue\" content=\"[link_rating]\">[link_rating_stars]
[optional <br /><span itemprop=\"description\">[link_description]</span>||]
[optional <br />[fb_button]||]
</div>\n";

// taking care of translations
$plugin_dir = plugin_basename( dirname( __FILE__ ) .'/languages' );
load_plugin_textdomain( 'links-shortcode', null, $plugin_dir );

// enable Links
$linkssc_enable_links_manager = get_option('linkssc_enable_links_manager', 'no');

// start showing the Links manager, even if there are no links
add_filter( 'pre_option_link_manager_enabled', '__return_true' );

// Hook for adding admin menus
if ( is_admin() ){ // admin actions
	// enable Links menu
	//if ($linkssc_enable_links_manager == "yes") 
	//{
		add_action('admin_menu', 'linkssc_add_options_page'); // add option page for plugin to Links menu
	//}
	//else
	//{
		//add_action('admin_menu', 'linkssc_add_settings_page'); // add option page for plugin to Settings menu
	//}	
	add_action('admin_init', 'linkssc_register_mysettings');
	add_action('admin_head', 'linkssc_add_LastMod_box'); // add last updated meta box on link editing page
	add_action('edit_link', 'linkssc_update_link_editied'); // update link edited field on editing a link
	add_action('add_link', 'linkssc_update_link_editied'); // update link edited field on adding a link
} 
else {
  // non-admin enqueues, actions, and filters
}

// action function for above hook
function linkssc_add_options_page() 
{
    // Add a new submenu under Links:
    add_submenu_page( 'link-manager.php', __('Links Shortcode','links-shortcode'), __('Links Shortcode','links-shortcode'), 'manage_options', 'links-shortcode-settings', 'linkssc_options_page');
}
function linkssc_add_settings_page() 
{
    // Add a new submenu under Settings:
    add_options_page(__('Links Shortcode','links-shortcode'), __('Links Shortcode','links-shortcode'), 'manage_options', 'links-shortcode-settings', 'linkssc_options_page');
}


$linkssc_css = get_option('linkssc_default_css', 'yes');
if ($linkssc_css == '') { $linkssc_css = 'yes'; update_option('linkssc_default_css', 'yes'); } // because WordPress sometimes does not handle this correct 
if ($linkssc_css == "yes") {
	add_action( 'wp_enqueue_scripts', 'linkssc_css' );
}
function linkssc_css() 
{
	// added for SSL friendlyness:
	wp_register_style( 'linkssc-style', plugins_url('links-shortcode.css', __FILE__) );
	wp_enqueue_style( 'linkssc-style' );
	//previously: echo '<link rel="stylesheet" type="text/css" media="screen" href="'. WP_PLUGIN_URL . '/links-shortcode/links-shortcode.css"/>';	
}

function linkssc_update_info() {
	if ( $info = wp_remote_fopen("http://www.apprique.com/links-shortcode-latest.txt") )
		echo '<br />' . strip_tags( $info, "<br><a><b><i><span>" );
}
add_action('in_plugin_update_message-'.plugin_basename(__FILE__), 'linkssc_update_info');


function linkssc_getdate($text)
{
	$result = new StdClass; 
	
	if(preg_match("/\d\d\d\d-\d\d-\d\d:/",$text))
	{
		$result->date = substr($text,0,10);
		$result->title = substr($text,11);
	} 
	else
	{
		$result->date = '';
		$result->title = $text;	
	}
	return $result;
}

add_shortcode('links', 'linkssc_shortcode');
function linkssc_shortcode($atts, $content = null) 
{
	global $linkssc_default_template;
	$fblike = '';
	$fbrecommend = '';
	$facebook = get_option('linkssc_facebook', 'like');
	$fbcolors = get_option('linkssc_fbcolors', 'light');
	$template = get_option('linkssc_template', $linkssc_default_template);
		if ($template=='') { $template = $linkssc_default_template; update_option('linkssc_template', $linkssc_default_template); }
	$template_before = get_option('linkssc_template_b', '');
	$template_after = get_option('linkssc_template_a', '');
	
	if ($facebook == 'like') { $fblike = '1'; }
	elseif ($facebook == 'recommend') {$fbrecommend = '1'; } 

	extract(shortcode_atts(array(
			'fblike'		 => $fblike,
			'fbrecommend'	 => $fbrecommend,
			'fbcolors'		 => $fbcolors,
			'orderby'        => get_option('linkssc_orderby', 'name'), 
			'order'          => get_option('linkssc_order', 'DESC'),
			'limit'          => get_option('linkssc_howmany', '-1'), 
			'category'       => null,
			'category_name'  => null, 
			'hide_invisible' => 1,
			'show_updated'   => 0, 
			'include'        => null,
			'exclude'        => null,
			'search'         => '',
			'get_categories' => 0, // TODO if 1, a separate query will be ran below to retrieve category names and the field [category_name] will become available for use in a template
			'links_per_page' => 0, // if > 0 links will be split in pages and 
			'links_list_id'  => '',
			'class'          => '',
			'alttext'		 => '',
			'emptystarsimg'  => plugins_url( 'emptystars.png', __FILE__ ),
			'fullstarsimg'   => plugins_url( 'fullstars.png', __FILE__ )
			), $atts)
	);
	
	$args = array(
            'orderby'        => $orderby, 
            'order'          => $order,
            'limit'          => $limit, 
            'category'       => $category,
            'category_name'  => $category_name, 
            'hide_invisible' => $hide_invisible,
            'show_updated'   => $show_updated, 
            'include'        => $include,
            'exclude'        => $exclude,
            'search'         => $search);
			
	// for compatibility with 'My link Order' plugin
	if ($orderby == 'order' && function_exists('mylinkorder_get_bookmarks'))
	{
		$bms = mylinkorder_get_bookmarks( $args );
	}
	else 
	{
		$bms = get_bookmarks( $args );
    }
	
	// if category names need to be retrieved
	if ($get_categories)
	{
		
	//TODO	
		
	//	$query = "Select object_id, wp_terms.name , wp_terms.slug from wp_terms
    //      inner join wp_term_taxonomy on wp_terms.term_id =
    //      wp_term_taxonomy.term_id
    //      inner join wp_term_relationships wpr on wpr.term_taxonomy_id =
    //      wp_term_taxonomy.term_taxonomy_id
    //      where taxonomy= 'category'
    //      order by object_id;";
		
	}
	
	// compatibility of default template in case FB button should not be shown
	if ($fblike == '1'|| $fbrecommend == '1')
	{
		if ($fblike == '1') { $fbaction = 'like'; } else { $fbaction = 'recommend'; } 
	}
	else
	{
		// replace DIV style from class="links_sc_fb" to class="links_sc"
		$template = str_replace('"links_sc_fb"', '"links_sc"',$template);
	}
	
	// calculate pagination details if applicable
	$countlinks = count($bms);
	$pagenav = ''; // will be assigned if pagination is necessary
	if (($links_per_page > 0) && ($countlinks > 0)) // if $links_per_page == 0, the logic below is irrelevant and all links will be shown
	{
		if (isset($_GET['links_list_id']) && isset($_GET['links_page']) && ($links_list_id == $_GET['links_list_id']) && is_numeric($_GET['links_page']))
		{
			$links_page = max(abs(intval($_GET['links_page'])),1); // page number is minimal 1;

			$start = ($links_page - 1) * $links_per_page; // the first link is number 0;
			$end = min($countlinks - 1, $start + $links_per_page - 1); // '-1' because start is also included and the lowest start is 0
			if ($end < $start) // then someone tried to enter a page number that does not exists -> go to first page
			{
				$start = 0;
				$end = $links_per_page - 1;
				$links_page = 1;
			}
			
		}
		else
		{
			$start = 0;
			$end = $links_per_page - 1;
			$links_page = 1;
		}
		$next_page = 0;
		$previous_page = 0;
		$page_links = array();
		if ($start - $links_per_page >= 0)
		{
			$previous_page = $links_page - 1;
			$page_links[] = '<a href="./?links_page='.$previous_page.'&links_list_id='.$links_list_id.'">'.__('Previous page','links-shortcode').'</a>';
		}
		if ($countlinks > $start + $links_per_page)
		{	
			$next_page = $links_page + 1;
			$page_links[] = '<a href="./?links_page='.$next_page.'&links_list_id='.$links_list_id.'">'.__('Next page','links-shortcode').'</a>';
		}
		$pagenav = '<div class="links-page-nav">'.join(' - ', $page_links).'</div>'; // TODO: do this better, in UL/LI format
		
	}
		
	$text = $template_before;
	$link_no = 0;
	foreach ($bms as $bm)
	{
		if ($links_per_page == 0 || ($link_no >= $start && $link_no <= $end) )
		{
			$newlinktext = $template.'';
			$title = linkssc_getdate($bm->link_name);
			$linkinfo = array();
			$linkinfo['class'] = $class; // this is a setting on the shortcode, but it can be added into the template for custom styling
			$linkinfo['link_name'] = $title->title;
			$linkinfo['link_url'] = $bm->link_url;
			$linkinfo['link_rel'] = $bm->link_rel;
			$linkinfo['link_image'] = $bm->link_image;
			$linkinfo['link_target'] = $bm->link_target;
			if (isset($bm->link_category)) {$linkinfo['link_category'] = $bm->link_category;} // because $bm->link_category is in most cases not set. TODO: find better solution
			$linkinfo['link_description'] = $bm->link_description;
			$linkinfo['link_visible'] = $bm->link_visible;
			$linkinfo['link_owner'] = get_the_author_meta('display_name', $bm->link_owner); // display the display name of a user instead of the user id.
			$linkinfo['link_rating'] = $bm->link_rating;
			$linkinfo['link_rating_stars'] = '<div class="links_sc_rating "><img class="links_sc_rating_full" src="'. $fullstarsimg . '" style="width:'.round(78*$linkinfo['link_rating']/10).'px;"/><img class="links_sc_rating_empty" src="'. $emptystarsimg . '" /></div>';
			if (preg_match('#^[\-0 :]*$#', $bm->link_updated)) { $linkinfo['link_updated'] = ''; $linkinfo['date'] = ''; } 
			else {
				$linkinfo['link_updated'] = $bm->link_updated;
				$a = explode(' ', $bm->link_updated); $linkinfo['date'] = $a[0];
			}
			if ($title->date != '') { $linkinfo['date'] = $title->date; }
			list($linkinfo['date_year'],$linkinfo['date_month'],$linkinfo['date_day']) = explode('-', $linkinfo['date']);
			$linkinfo['link_rel'] = $bm->link_rel;
			$linkinfo['link_notes'] = $bm->link_notes;
			$linkinfo['link_rss'] = $bm->link_rss;
			if ($fblike == '1'|| $fbrecommend == '1')
			{
				$linkinfo['fb_button'] = '<iframe src="//www.facebook.com/plugins/like.php?href='.urlencode($bm->link_url).'&amp;layout=standard&amp;show_faces=false&amp;width=450&amp;action='.$fbaction.'&amp;font&amp;colorscheme='.$fbcolors.'" scrolling="no" frameborder="0" ></iframe>';
			}
			else { $linkinfo['fb_button'] = ''; }
			$reallinkinfo = array_diff($linkinfo, array('')); // remove all elements with empty value;
			// insert al known values
			foreach ($reallinkinfo as $k=>$v)
			{
				$newlinktext = str_replace('['.$k.']',$v,$newlinktext);
			}
			// resolve optional elements
			$c = preg_match_all ('/\[optional (.*)\|\|(.*)\]/U',$newlinktext,$optionals, PREG_PATTERN_ORDER);
			for (;$c > 0;$c--)
			{
				if ((preg_match('/\[(.*)\]/U',$optionals[1][$c-1],$tag)) && (isset($linkinfo[$tag[1]]))) 
				{
					$newlinktext = str_replace ($optionals[0][$c-1],$optionals[2][$c-1],$newlinktext); 
				}
				else
				{
					$newlinktext = str_replace ($optionals[0][$c-1],$optionals[1][$c-1],$newlinktext); 
				}
			}
			foreach ($linkinfo as $k=>$v)
			{
				$newlinktext = str_replace('['.$k.']','',$newlinktext);
			}
			
			$text .= $newlinktext; 
			// for testing only:
			//$text .= print_r($bm,true).'<br>';
		}
		$link_no++;
    }
	$text .= $template_after;
	
	if ($countlinks == 0 && $alttext != '') {
		$text = $alttext;
	}
	
	return '<!-- Links -->'.do_shortcode($text)."\n".$pagenav.'<!-- /Links -->'; // add html comment for easier debugging
}



// Activation action
function linkssc_activation(){
	global $linkssc_default_template;
	add_option('linkssc_facebook', 'like' );
	add_option('linkssc_fbcolors', 'light' );
	add_option('linkssc_orderby', 'name');
	add_option('linkssc_order', 'DESC'); 
	add_option('linkssc_howmany', '-1');
	add_option('linkssc_template', $linkssc_default_template); 
	add_option('linkssc_template_b', ''); 
	add_option('linkssc_template_a', ''); 
	add_option('linkssc_default_css', 'yes'); 
}
register_activation_hook( __FILE__, 'linkssc_activation' );

//Uninstalling Action
function linkssc_uninstall(){
	delete_option('linkssc_facebook');	
	delete_option('linkssc_fbcolors');
	delete_option('linkssc_orderby');
	delete_option('linkssc_order');
	delete_option('linkssc_howmany');
	delete_option('linkssc_template');
	delete_option('linkssc_template_b'); 
	delete_option('linkssc_template_a'); 
	delete_option('linkssc_default_css'); 
}
register_uninstall_hook( __FILE__, 'linkssc_uninstall' );

function linkssc_register_mysettings() { // whitelist options
	register_setting( 'links-shortcode-settings', 'linkssc_facebook' );
	register_setting( 'links-shortcode-settings', 'linkssc_fbcolors' );
	register_setting( 'links-shortcode-settings', 'linkssc_orderby' );
	register_setting( 'links-shortcode-settings', 'linkssc_order' );
	register_setting( 'links-shortcode-settings', 'linkssc_howmany' );
	register_setting( 'links-shortcode-settings', 'linkssc_template' );
	register_setting( 'links-shortcode-settings', 'linkssc_template_b' );
	register_setting( 'links-shortcode-settings', 'linkssc_template_a' );	
	register_setting( 'links-shortcode-settings', 'linkssc_default_css' ); 
}

function linkssc_options_page() 
{
	global $linkssc_default_template;
	if (!current_user_can( 'manage_options' ) ) {
		wp_die ( __( 'You do not have sufficient permissions to access this page' ) );
	}
	$css = get_option('linkssc_default_css', 'yes');
		if ($css=='') { $css = 'yes'; update_option('linkssc_default_css', 'yes'); }
	$facebook = get_option('linkssc_facebook', 'like');
	$fbcolors = get_option('linkssc_fbcolors', 'light');
	$template = get_option('linkssc_template', $linkssc_default_template);
		if ($template=='') { $template = $linkssc_default_template; update_option('linkssc_template', $linkssc_default_template); }
	$template_b = get_option('linkssc_template_b', '');
	$template_a = get_option('linkssc_template_a', '');
	
	$orderby = get_option('linkssc_orderby', 'name');
	$order = get_option('linkssc_order', 'DESC');
	$howmany = get_option('linkssc_howmany', '-1');
	?>
	<div class="wrap">
	<div class="postbox" style="float:right;width:100px;margin:20px"><div class="inside" style="margin:10px"><?php _e('Like this plugin? Saves you work? Or using it in a professional context? A small contribution is highly appreciated.', 'links-shortcode'); ?><p>
	<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank" style="width:100%;">
		<input type="hidden" name="cmd" value="_s-xclick">
		<input type="hidden" name="encrypted" value="-----BEGIN PKCS7-----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-----END PKCS7-----
		">
		<input type="image" src="https://www.paypalobjects.com/en_GB/i/btn/btn_donate_LG.gif" border="0" name="submit" alt="PayPal � The safer, easier way to pay online." style="margin-left:auto;margin-right:auto">
		<img alt="" border="0" src="https://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1">
	</form>
	<?php _e('Or donate Bitcoins to this address: <a style="font-size:11px" href="bitcoin:12nxdhSBXNBdp2yG3oXzRkiArRgztSRG9f" target="_blank" title="Click here to send this address to your wallet (if your wallet is not compatible you will get an empty page, close the white screen and copy the address by hand). Thank you very much for any contribution!">12nxdhSBXNBdp2yG3oXzRkiArRgztSRG9f</a> ', 'links-shortcode'); ?>
	</div></div>
		
	<h2> <?php _e('Links Shortcode plugin settings','links-shortcode'); ?> </h2>

	<form method="post" action="options.php">
	<?php settings_fields( 'links-shortcode-settings' ); ?>	
	
	<input type="hidden" name="action" value="update" />
	<input type="hidden" name="page_options" value="linkssc_list" />	

	<h3> <?php _e('Default settings for the Links shortcode','links-shortcode'); ?></h3>
	<?php _e('Here you can specify the default options used when you used the [links] shortcode. You can overrule this on the shortcode itself, if you want.','links-shortcode'); ?><br />
	<?php _e('For help on using the shortcode (and for voting), please visit the plugin page on <a href="https://wordpress.org/extend/plugins/links-shortcode/" target="_blank">wordpress.org</a> or <a href="http://www.apprique.com/community/wordpress-plugins" target="_blank">our website</a>.','links-shortcode'); ?>
	<table class="form-table">
		<tr valign="top">
		<th scope="row"><?php _e('Show a facebook Like or Recommend button?','links-shortcode'); ?></th>
		<td><input type="radio" name="linkssc_facebook" value="like" <?php if ($facebook == 'like') echo 'CHECKED'; ?> /><?php _e('Yes, a Like button','links-shortcode'); ?><br />
			<input type="radio" name="linkssc_facebook" value="recommend" <?php if ($facebook == 'recommend') echo 'CHECKED'; ?> /><?php _e('Yes, a Recommend button','links-shortcode'); ?><br />
			<input type="radio" name="linkssc_facebook" value="none" <?php if ($facebook == 'none') echo 'CHECKED'; ?> /><?php _e('No','links-shortcode'); ?><br />
		</td>
		</tr>
		<!-- Change fb color sceme -->
		<!--<tr valign="top">
		<th scope="row"><?php _e('What facebook color scheme?','links-shortcode'); ?></th>
		<td><input type="radio" name="linkssc_fbcolors" value="light" <?php if ($fbcolors == 'light') echo 'CHECKED'; ?> /><?php _e('Light','links-shortcode'); ?><br />
			<input type="radio" name="linkssc_fbcolors" value="dark" <?php if ($fbcolors == 'dark') echo 'CHECKED'; ?> /><?php _e('Dark','links-shortcode'); ?><br />
		</td>
		</tr>-->
		<!-- End change fb color sceme -->
        <tr valign="top">
        <th scope="row"><?php _e('What to order your links by?','links-shortcode'); ?></th>
        <td><input type="radio" name="linkssc_orderby" value="name" <?php if ($orderby == 'name') echo 'CHECKED'; ?> /><?php _e('Link name','links-shortcode'); ?><br />
			<input type="radio" name="linkssc_orderby" value="description" <?php if ($orderby == 'description') echo 'CHECKED'; ?> /><?php _e('Link description','links-shortcode'); ?><br />
			<input type="radio" name="linkssc_orderby" value="url" <?php if ($orderby == 'url') echo 'CHECKED'; ?> /><?php _e('Link url','links-shortcode'); ?><br />
			<input type="radio" name="linkssc_orderby" value="owner" <?php if ($orderby == 'owner') echo 'CHECKED'; ?> /><?php _e('Link owner, the user who added the link in the Links Manager','links-shortcode'); ?><br />
			<input type="radio" name="linkssc_orderby" value="rating" <?php if ($orderby == 'rating') echo 'CHECKED'; ?> /><?php _e('Link rating','links-shortcode'); ?><br />
			<input type="radio" name="linkssc_orderby" value="rand" <?php if ($orderby == 'rand') echo 'CHECKED'; ?> /><?php _e('Random','links-shortcode'); ?><br />
	<?php if (is_plugin_active('my-link-order/mylinkorder.php')) { ?>
			<input type="radio" name="linkssc_orderby" value="order" <?php if ($orderby == 'order') echo 'CHECKED'; ?> /><?php _e('As indicated using the My Link Order plugin','links-shortcode'); ?><br/>
	<?php } ?></td>
        </tr>

        <tr valign="top">
        <th scope="row"><?php _e('How to order?','links-shortcode'); ?></th>
        <td><input type="radio" name="linkssc_order" value="ASC" <?php if ($order == 'ASC') echo 'CHECKED'; ?> /><?php _e('Ascending','links-shortcode'); ?><br />
			<input type="radio" name="linkssc_order" value="DESC" <?php if ($order == 'DESC') echo 'CHECKED'; ?> /><?php _e('Descending','links-shortcode'); ?>
		</td>
        </tr>
		
        <tr valign="top">
        <th scope="row"><?php _e('How many links to show? (-1 for all)','links-shortcode'); ?></th>
        <td><input type="text" name="linkssc_howmany" value="<?php echo $howmany; ?>"  /><br />
		</td>
        </tr>
		
		<tr valign="top">
        <th scope="row"><?php _e('How to display the links?','links-shortcode'); ?></th>
        <td><textarea name="linkssc_template" class="large-text code" rows="10"><?php echo $template; ?></textarea><br>
		<?php _e('The following codes can be used in the template: [link_url], [link_name], [link_image], [link_target], [link_description], [link_visible], [link_owner], [link_rating] (display as a number), [link_rating_stars] (display 0-5 stars), [link_updated] (only if not zero, otherwise empty), [link_rel], [link_notes], [link_rss], [fb_button]. You can provide alternative html to display in case a description, image or other property is not available for a link. See examples below.<br />
		The syntax is <b>[optional a||b]</b>, where b can be left empty, resulting in <b>[optional a||]</b>, (as in the examples below).','links-shortcode'); ?>
		</td>
        </tr>
		
		<tr valign="top">
        <th scope="row"><?php _e('Provide an optional text or html to display before the links:','links-shortcode'); ?></th>
        <td><textarea name="linkssc_template_b" class="large-text code" rows="2"><?php echo $template_b; ?></textarea><br />
		<?php _e('You can use this for example to display links in a table. Example:','links-shortcode'); ?><pre>&lt;table></pre></td>
		</tr>
		
		<tr valign="top">
        <th scope="row"><?php _e('Provide an optional text or html to display after the links:','links-shortcode'); ?></th>
        <td><textarea name="linkssc_template_a" class="large-text code" rows="2"><?php echo $template_a; ?></textarea><br />
		<?php _e('Example:','links-shortcode'); ?><pre>&lt;/table></pre></td>
		</tr>
		
		<tr valign="top">
		<th scope="row"><?php _e('Include a default stylesheet (css) for formatting the template?','links-shortcode'); ?></th>
		<td><input type="radio" name="linkssc_default_css" value="yes" <?php if ($css == 'yes') echo 'CHECKED'; ?> /><?php _e('Yes','links-shortcode'); ?><br />
			<input type="radio" name="linkssc_default_css" value="no" <?php if ($css == 'no') echo 'CHECKED'; ?> /><?php _e('No','links-shortcode'); ?><br />
		</td>
		</tr>
		
		<tr valign="top">
        <th scope="row"></th>
        <td><p class="submit"><input type="submit" class="button-primary" value="<?php _e('Save Changes','links-shortcode'); ?>" /></p></td>
		</tr>	
		
		<tr valign="top">
			<th scope="row"><?php _e('Examples','links-shortcode'); ?></th>
			<td>
				<b><?php _e('A list of links','links-shortcode'); ?></b><br /><?php _e('To show all links in a list with a Facebook like button and an optional link rating and using schema.org search engine optimization, use the following main template:','links-shortcode'); ?><br />
				<pre style="margin-left:50px;"><?php echo htmlspecialchars($linkssc_default_template); ?></pre>
				<?php _e('<b>NB</b>: For compatibility reasons, in the example above in case you choose not to include a Facebook button, the plugin will automatically correct the DIV class from <b>\'links_sc_fb\'</b> to <b>\'links_sc\'</b> for optimal spacing.','links-shortcode'); ?>
				<?php _e('Leave the \'before\' and \'after\' template fields empty.','links-shortcode'); ?><br /><br />
				<b><?php _e('Links in a table','links-shortcode'); ?></b><br /><?php _e('To show all links in a table with images in a separate column at the left if available, use the following main template:','links-shortcode'); ?><br />
				<pre style="margin-left:50px;"><?php echo htmlspecialchars('<tr style="width:100%;">
<td style="width:100px;vertical-align:top">
[optional <a href="[link_url]" target=_blank><img src="[link_image]" border=0 style="width:100px"></a>||]
</td><td>
<div class="links_sc_fb" style="text-align:left">
[optional [date]: ||]<a href="[link_url]" target="_blank">[link_name]</a>
[optional <br />[link_description]||]
[optional <br />[fb_button]||]
</div>
</td></tr>'); ?>
				</pre>
				<?php _e('Enter the following in the \'before\' field:','links-shortcode'); ?><br />
				<pre style="margin-left:50px;"><?php echo htmlspecialchars('<table style="margin:0;padding:0;">'); ?></pre>
				<?php _e('And the following in the \'after field\':','links-shortcode'); ?>
				<pre style="margin-left:50px;"><?php echo htmlspecialchars('</table>'); ?></pre><br /><br />
			</td>
		</tr>
	</table>
	</form>
	</div>
	<?php
}

function linkssc_add_donate_link($links, $file) 
{
	static $this_plugin;
	if (!$this_plugin) $this_plugin = plugin_basename(__FILE__);
	if ($file == $this_plugin)
	{
		$donate_link = '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=79AKXNVRT8YSQ&lc=NL&item_name=Links%20Shortcode%20plugin%20by%20Maarten&item_number=Links%20Shortcode%20plugin&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted" target="_blank">'.__('Donate', 'links-shortcode').'</a>';
		$links[] = $donate_link;
	}
	return $links;
}
add_filter('plugin_row_meta', 'linkssc_add_donate_link', 10, 2 );

/*
The following enables filling the link_edited field for a link with a date when the link has been created or edited. 
It is based on "Andys Link Last Edited Meta Box" as described on http://fleacircusdir.livejournal.com/5498.html
(original author: AGC based on the work of Ozh and miekd)
*/

//See http://www.code-styling.de/english/how-to-use-wordpress-metaboxes-at-own-plugins
//    http://planetozh.com/blog/2008/02/wordpress-snippet-add_meta_box/
//    http://wordpress.org/extend/plugins/link-updated/
//    http://codex.wordpress.org/Function_Reference/add_meta_box

// function to update the link_edited field
function linkssc_update_link_editied($link_ID) {
    global $wpdb;
    $sql = "update ".$wpdb->links." set link_updated = NOW() where link_id = " . $link_ID . ";";
    $wpdb->query($sql);
}

// add meta box to show this date in the link editing screen
function linkssc_add_LastMod_box() {
    
    add_meta_box(
        'linkssclinkmodifieddiv', // id of the <div> we'll add
        'Last Modified', //title
        'linkssc_meta_box_add_last_modfied', // callback function that will echo the box content
        'link', // where to add the box: on "post", "page", or "link" page
        'side'  // location, 'normal', 'advanced', or 'side'
    );

}
// This function echoes the content of our meta box
function linkssc_meta_box_add_last_modfied($link) {
     if (! empty($link->link_id))
     {
    echo "Last Modified Date: ";
    echo $link->link_updated;
    }
    else
    { echo "New Link";}
}


?>