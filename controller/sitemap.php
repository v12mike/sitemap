<?php
/**
*
* @package phpBB Extension - v12mike XML Sitemap
* @copyright (c) 2014 tas2580 (https://tas2580.net)
* @copyright (c) 2018 v12mike (morrinmike@gmail.com)
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace v12mike\sitemap\controller;

define('MAX_MAP_SIZE', 200);  // topics per map file, should be no larger than 10,000
define('INCLUDE_VIEWFORUM_PAGES', false);  // set to true to include viewforum pages in the sitemap
define('USE_POST_TIME', true);  // set to true to fetch correct last post time for each topic page (adds processing load)
define('POST_PAGES_PER_QUERY', 100);  // the number of post pages of posts to fetch in each query
define('RESULTS_CACHE_TIME', 3600);  // cache time (seconds), set to 0 to disable caching

use Symfony\Component\HttpFoundation\Response;

class sitemap
{
	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver */
	protected $db;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \phpbb\event\dispatcher_interface */
	protected $phpbb_dispatcher;

	/** @var string php_ext */
	protected $php_ext;

	/** @var string */
	protected $phpbb_extension_manager;

	/**
	* Constructor
	*
	* @param \phpbb\auth\auth						$auth						Auth object
	* @param \phpbb\config\config					$config						Config object
	* @param \phpbb\db\driver\driver_interface		$db							Database object
	* @param \phpbb\controller\helper				$helper						Helper object
	* @param string									$php_ext					phpEx
	* @param \phpbb_extension_manager				$phpbb_extension_manager	phpbb_extension_manager
	*/
	public function __construct(\phpbb\auth\auth $auth,
								\phpbb\config\config $config,
								\phpbb\db\driver\driver_interface $db,
								\phpbb\controller\helper $helper,
								\phpbb\event\dispatcher_interface $phpbb_dispatcher,
								\phpbb\content_visibility $content_visibility,
								$php_ext,
								$phpbb_extension_manager)
	{
		$this->auth = $auth;
		$this->config = $config;
		$this->db = $db;
		$this->helper = $helper;
		$this->phpbb_dispatcher = $phpbb_dispatcher;
		$this->content_visibility = $content_visibility;
		$this->php_ext = $php_ext;
		$this->phpbb_extension_manager = $phpbb_extension_manager;

		$this->board_url = generate_board_url();
	}

	/**
	 * Generate sitemap for a forum
	 *
	 * @param int		$id		The forum ID
	 * @param int		$first	The first topic ID (0 to include viewforum pages)
	 * @param int		$last	The last topic ID
	 * @return object
	 */
	public function sitemap($forum, $start)
	{
		if (!$this->auth->acl_get('f_list', $forum))
		{
			trigger_error('SORRY_AUTH_READ');
		}

		$sql = 'SELECT forum_id, forum_name, forum_last_post_time, forum_topics_approved
			FROM ' . FORUMS_TABLE . '
			WHERE forum_id = ' . (int) $forum;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		// generate map of viewforum pages
		if ($start == 0)
		{
			if (INCLUDE_VIEWFORUM_PAGES)
			{
				$topic = 0;
				do
				{
					// URL for the forum
					$url = $this->board_url . '/viewforum.' . $this->php_ext . '?f=' . $forum . ($topic ? '&amp;start=' . $topic : '');
					$url_data[] = array(
						'url'	=> $url,
						'time'	=> $row['forum_last_post_time'],
						'start'	=> $topic
					);
					$topic += $this->config['topics_per_page'];
				}
				while ($topic < $row['forum_topics_approved']);
			}
			else
			{
				trigger_error('UNABLE_TO_DELIVER_FILE');
			}
		}
		else
		{
			// Generate map of topics within selected range
			$sql = 'SELECT topic_id, topic_title, topic_last_post_time, topic_posts_approved
				FROM ' . TOPICS_TABLE . '
				WHERE forum_id = ' . (int) $forum . '
				AND topic_id >= ' . $start . '
				AND ' . $this->content_visibility->get_visibility_sql('topic', $forum) .'
				AND topic_visibility = ' . ITEM_APPROVED . '
				AND topic_status <> ' . ITEM_MOVED . '
				ORDER BY topic_id ASC' . '
				LIMIT ' . (int)MAX_MAP_SIZE;
			$result = $this->db->sql_query($sql);
			while ($topic_row = $this->db->sql_fetchrow($result))
			{
				// Put forum data to each topic row
				$topic_row['forum_id'] = $forum;

				if ($topic_row['topic_posts_approved'] > $this->config['posts_per_page'])
				{
					// For topics with multiple pages of posts, generate an entry for each page
					$post_offset = $post_fetch_offset = 0;
					$last_post_offset = $this->config['posts_per_page'] - 1;
					$posts_to_fetch = $this->config['posts_per_page'] * POST_PAGES_PER_QUERY;
					// find the post time of the last post on each topic page
					do
					{
						if (USE_POST_TIME)
						{
							// fetch upto $posts_to_fetch posts from the db
							$post_fetch_offset += $posts_to_fetch;
							$sql = 'SELECT p.post_time
								FROM ' . POSTS_TABLE . ' p' . '
								WHERE p.topic_id = ' . $topic_row['topic_id'] . '
								AND ' . $this->content_visibility->get_visibility_sql('post', $forum, 'p.');
							$posts_data = $this->db->sql_query_limit($sql, $posts_to_fetch, $post_fetch_offset, RESULTS_CACHE_TIME);
						}
						do
						{
							// URL for topic
							$url = $this->board_url . '/viewtopic.' . $this->php_ext . '?t=' . $topic_row['topic_id'] . ($post_offset ?  '&amp;start=' . $post_offset : '');
							$post_offset += $this->config['posts_per_page'];
							if (USE_POST_TIME && ($post_offset < $topic_row['topic_posts_approved']))
							{
								$db_time = $this->db->sql_fetchfield('post_time', $post_offset - 1, $posts_data);
								if ($db_time === false)
								{
									// if the (possibly cached) data set does not have the record we need, use the value from the topic table
									$time = $topic_row['topic_last_post_time'];
								}
								else
								{
									$time = $db_time;
								}
							}
							else
							{
								// for the last page, use the time from the topic table
								$time = $topic_row['topic_last_post_time'];
							}
							$url_data[] = array(
								'url'	=> $url,
								'time'	=> $time,
								'start'	=> $post_offset
							);
						} while (($post_offset < $topic_row['topic_posts_approved']) && // loop over pages of a topic
								 ($post_offset < $post_fetch_offset + $posts_to_fetch)); // but not beyond posts fetched

						if (USE_POST_TIME)
						{
							$this->db->sql_freeresult($posts_data);
						}
					} while (USE_POST_TIME && ($post_fetch_offset < $topic_row['topic_posts_approved']));  // loop over groups of pages of really long topics
				}
				else
				{
					// topic with a single page of posts
					$url_data[] = array(
						'url'	=> $this->board_url . '/viewtopic.' . $this->php_ext . '?t=' . $topic_row['topic_id'],
						'time'	=> $topic_row['topic_last_post_time'],
						'start'	=> 0
					);
				}
			}
			$this->db->sql_freeresult($result);
		}
		return $this->output_sitemap($url_data, 'urlset');
	}

	/**
	 * Generate sitemap index
	 *
	 * @return object
	 */
	public function index()
	{
		$sql = 'SELECT forum_id, forum_name, forum_last_post_time
			FROM ' . FORUMS_TABLE . '
			WHERE forum_type = ' . (int) FORUM_POST . '
			ORDER BY forum_id ASC';
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forum_id = $row['forum_id'];
			if ($this->auth->acl_get('f_list', $forum_id))
			{
				// optionally we can add viewforum pages to the map
				if (INCLUDE_VIEWFORUM_PAGES)
				{
					$url_data[] = array(
						'url'		=> $this->helper->route('v12mike_sitemap_sitemap',
															array('forum' => $forum_id, 'start' => 0),
															true,
															'',
															\Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
						'time'		=> $row['forum_last_post_time'],
						'row'		=> $row,
						'start'		=> 0
					);
				}
				// break the topics into smaller maps if required
				$begin_topic_id = 1;
				do
				{
					// Get all topics in the forum
					$sql = 'SELECT COUNT(topic_set.topic_id) as count, MAX(topic_set.topic_last_post_time) as latest_time, MAX(topic_set.topic_id) as end_topic_id
						FROM (
						SELECT topic_id, topic_last_post_time
						FROM ' . TOPICS_TABLE . '
						WHERE forum_id = ' . (int) $forum_id . '
						AND topic_id >= ' . (int) $begin_topic_id . '
						ORDER BY topic_id ASC
						LIMIT ' . (int) MAX_MAP_SIZE . ')
						AS topic_set' ;
					$topic_data = $this->db->sql_query($sql);
					$topic_row = $this->db->sql_fetchrow($topic_data);
					$this->db->sql_freeresult($topic_data);
					if ($topic_row['count'])
					{
						$end_topic_id = $topic_row['end_topic_id'];
						$url_data[] = array(
							'url'		=> $this->helper->route('v12mike_sitemap_sitemap',
																array('forum' => $forum_id, 'start' => $begin_topic_id),
																true,
																'',
																\Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
							'time'		=> $topic_row['latest_time'],
						);
						$begin_topic_id = $end_topic_id + 1;
					}
				} while ($topic_row['count']);
			}
		}
		$this->db->sql_freeresult($result);
		return $this->output_sitemap($url_data, 'sitemapindex');
	}

	/**
	 * Generate the XML sitemap
	 *
	 * @param array	$url_data
	 * @param string	$type
	 * @return Response
	 */
	private function output_sitemap($url_data, $type = 'sitemapindex')
	{
		$style_xsl = $this->board_url . '/'. $this->phpbb_extension_manager->get_extension_path('v12mike/sitemap', false) . 'style.xsl';

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<?xml-stylesheet type="text/xsl" href="' . $style_xsl . '" ?>' . "\n";
		$xml .= '<' . $type . ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		$tag = ($type == 'sitemapindex') ? 'sitemap' : 'url';
		foreach ($url_data as $data)
		{
			$xml .= '	<' . $tag . '>' . "\n";
			$xml .= '		<loc>' . $data['url'] . '</loc>'. "\n";
			$xml .= ($data['time'] <> 0) ? '		<lastmod>' . gmdate('Y-m-d\TH:i:s+00:00', (int) $data['time']) . '</lastmod>' .  "\n" : '';
			$xml .= '	</' . $tag . '>' . "\n";
		}
		$xml .= '</' . $type . '>';

		$headers = array(
			'Content-Type'		=> 'application/xml; charset=UTF-8',
		);
		return new Response($xml, '200', $headers);
	}
}
