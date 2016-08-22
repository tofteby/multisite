<?php

namespace Drupal\custom_qr_generator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Database\Connection;
use Drupal\Component\Utility\UrlHelper;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;


class QRAdminController extends ControllerBase {

	protected $database;

	public static function create(ContainerInterface $container) {
		return new static(
			$container->get('database')
		);
	}


	public function __construct(Connection $database) {
		$this->database = $database;
	}


	public function content() {

		$link = sprintf('<a class="%s" href="%s/node/add/qr_node?destination=%sadmin/qr-admin">%s</a>'
			, 'button button-action button--primary button--small'
			, $GLOBALS['base_url']
			, $GLOBALS['base_path']
			, t('Create New QR Node')
		);

		$content = array();
		$content['link'] = array(
			'#type' => 'markup',
			'#markup' => '<p>' . $link . '</p>',
		);

		$content['qr_overview_table'] = $this->_overviewTable();
		$content['qr_overview_pager'] = array('#type' => 'pager');

		return $content;
	}


	private function _overviewTable() {

		// Table header
		$header = array(
			array(
				'data' => $this->t('Title'),
				'field' => 'nfd.title',
			),
			array(
				'data' => $this->t('URL (OUT)'),
				'field' => 'ncu.field_outgoing_url_value',
			),
      array(
				'data' => $this->t('URL (IN)'),
				'field' => 'nci.field_incoming_url_value',
			),
      array(
        'data' => $this->t('Category'),
        'field' => 'ncc.field_qr_category_target_id',
      ),
			array(
				'data' => $this->t('Statistics'),
				'field' => 'cqrs.url_redirections',
			),
			array(
				'data' => $this->t('Status'),
				'field' => 'cqrs.url_status',
			),
			'', // "QR Download" column
			'', // "Edit" column
			'', // "Delete" column
		);

		$rows = array();

		$query = $this->database->select('node', 'n')
			->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
			->extend('\Drupal\Core\Database\Query\TableSortExtender');

		$query->join('node_field_data', 'nfd', 'nfd.nid = n.nid');
		$query->join('node__field_outgoing_url', 'ncu', 'ncu.entity_id = n.nid');
    $query->join('node__field_incoming_url', 'nci', 'nci.entity_id = n.nid');
    $query->join('node__field_qr_category', 'ncc', 'ncc.entity_id = n.nid');
		$query->join('custom_qr_generator_stats', 'cqrs', 'cqrs.qrnid = n.nid');

		$query->fields('nfd', array('nid', 'type', 'title'));
		$query->fields('ncu', array('field_outgoing_url_value'));
    $query->fields('nci', array('field_incoming_url_value'));
    $query->fields('ncc', array('field_qr_category_target_id'));
		$query->fields('cqrs', array('url_redirections', 'url_status'));
		$query->condition('nfd.type', 'qr_node', '=');

		$node_storage = $query->limit(20)->orderByHeader($header)->execute();

		foreach ($node_storage as $ns) {
			$outgoing_url = $ns->field_outgoing_url_value;
      $incoming_url = $GLOBALS['base_url'] . $GLOBALS['base_path'] . 'qr/' . $ns->field_incoming_url_value;
      $category_url = $GLOBALS['base_url'] . $GLOBALS['base_path'] . 'taxonomy/term/' . $ns->field_qr_category_target_id;

			if (UrlHelper::isValid($outgoing_url, true)) {
				$outgoing_url = \Drupal::l($outgoing_url, Url::fromUri($outgoing_url));
			}

      if (!empty($incoming_url)) {
				$incoming_url = \Drupal::l($ns->field_incoming_url_value, Url::fromUri($incoming_url));
			} else {
        $incoming_url = print $this->t('N/A');
      }

      if (!empty($category_url)) {
        $entity = Term::load($ns->field_qr_category_target_id);
        $category_url = \Drupal::l($entity->label(), Url::fromUri($category_url));
      } else {
        $category_url = print $this->t('N/A');
      }

			$edit_link_url = sprintf('%s/node/%s/edit?destination=%sadmin/qr-admin'
				, $GLOBALS['base_url'], $ns->nid, $GLOBALS['base_path']
			);

			$delete_link_url = sprintf('%s/node/%s/delete?destination=%sadmin/qr-admin'
				, $GLOBALS['base_url'], $ns->nid, $GLOBALS['base_path']
			);

			$edit_link = \Drupal::l($this->t('Edit'), Url::fromUri($edit_link_url));
			$delete_link = \Drupal::l($this->t('Delete'), Url::fromUri($delete_link_url));

			$rows[] = array(
				'data' => array(
					$ns->title,
					$outgoing_url,
          $incoming_url,
          $category_url,
					$ns->url_redirections,
					$this->_styledStatus($ns->url_status),
					$this->_downloadLink($ns->nid),
					$edit_link,
					$delete_link,
				)
			);
		}

		return array(
			'#type' => 'table',
			'#header' => $header,
			'#rows' => $rows,
			'#empty' => $this->t('No data available.'),
		);
	}


	private function _styledStatus($status) {
		$color_class = '';

		if ($status == 'OK') {
			$color_class = ' cqrs-status-green';
		} elseif ($status == 'FAILED') {
			$color_class = ' cqrs-status-red';
		}

		return array(
			'data' => array(
				'#markup' => sprintf(
					'<div class="cqrs-status%s">%s</div>'
					, $color_class
					, $status
				)
			)
		);
	}


	private function _downloadLink($nid) {

		$node = \Drupal::entityManager()->getStorage('node')->load($nid);
		$file_missing = array(
			'data' => array('#markup' => '(' . t('File missing') . ')')
		);

		if (!isset($node->get('field_qr_img')->target_id)) {
			return $file_missing;
		}

		$qr_img_id = $node->get('field_qr_img')->target_id;
		$qr_img = File::load($qr_img_id);

		if (!$qr_img) {
			return $file_missing;
		}

		return \Drupal::l(
			t('Download QR'),
			Url::fromRoute('custom_qr_generator.qr_img_download',
				array('fid' => $qr_img_id)
			)
		);
	}
}
