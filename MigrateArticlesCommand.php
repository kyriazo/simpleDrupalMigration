<?php

//Custom module to assign the command to.
namespace Drupal\mig_articles\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\ContainerAwareCommand;
use Drupal\Console\Annotations\DrupalCommand;
use \Drupal\node\Entity\Node;
use \Drupal\media\Entity\Media;
use \Drupal\paragraphs\Entity\Paragraph;
use \Drupal\Core\Database\Database;
use Drupal\taxonomy\Entity\Term;

/**
 * Class MigrateArticlesCommand.
 *
 * @DrupalCommand (
 *     extension="mig_articles",
 *     extensionType="module"
 * )
 */
class MigrateArticlesCommand extends ContainerAwareCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('mig_articles:doit')
      ->setDescription($this->trans('commands.mig_articles.doit.description'));
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->getIo()->info('Migration starting!');

    $other_database = array(
      'database' => 'origin_d7_database',
      'username' => 'root', // assuming this is necessary
      'host' => '127.0.0.1', // assumes localhost
      'port' => '3306',
      'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
      'driver' => 'mysql', // replace with your database driver
    );
    //Add database connection info
    Database::addConnectionInfo('originDB', 'default', $other_database);
    //Connect to database
    $database = Database::getConnection('default', 'originDB');
    //Set active database
    db_set_active('originDB');
    //Start your query
    $query = $database->query(
    "SELECT b.entity_id,title,created,body_value,body_summary,filename,uri 
    from file_managed as a 
    join (
      select entity_id,field_image_fid 
      from field_data_field_image 
      where bundle='health_line' 
      or bundle='news'
      ) as b 
    on a.fid = b.field_image_fid
    join (
      select nid,title,created 
      from node 
      where type='health_line' 
      or type='news'
      ) as c 
    on b.entity_id=c.nid
    join (
        select entity_id,body_value,body_summary
        from field_data_body
        where bundle='health_line'
        or bundle='news'
    ) as d
    on b.entity_id=d.entity_id;");
    //Get results
    $result = $query->fetchAll();
    //Initialize migrated article counter
    $counter = 1;
    //For articles that contain file or media entities, search inside until all are eliminated via replaceImage();
    foreach ($result as $item) {
      $title = $item->title;
      $created = $item->created;
      $body = $item->body_value;
      if(strpos($body,'[[{"')) {

        $undone = true;
      
        while ($undone) {
          $body = replaceImage($body);
          if (!strpos($body,'[[{"')) {
            $undone=false;
          }
        }
      }
      $summary = $item->body_summary;
      $filename = $item->filename;
      $image_uri = $item->uri;
      $entity_id = $item->entity_id;
      $authors = getAuthors($entity_id);
      dump($counter);
      $counter++;
      processArticle($title,$created,$body,$summary,$filename,$image_uri,$authors);
      $authors = [];
    }
  }
}


function processArticle($title,$created,$body,$summary,$filename,$image_uri,$authors) {

      // Create file object from remote URL.
      $image_uri = explode("/",$image_uri,2);
      $image_url = "http://www.oldsite.gr/sites/default/files" . $image_uri[1];
      $data = file_get_contents($image_url);
      $file = file_save_data($data, "public://".$filename , FILE_EXISTS_REPLACE);
  
      //Create media entity and attach previous file
      $media = Media::create([
        'bundle'           => 'image',
        'field_image' => [
          'target_id' => $file->id(),
        ],
      ]);
  
      $media->setName('image' . $file->id())->setPublished(TRUE)->save();
  
  
      // Create node object with attached file.
      $node = Node::create([
        'type'        => 'article',
        'uid'      => 1,
        'layout_selection' => 'article_layout_1',
        'title'       => $title,
        'field_teaser_media' => $media->id(),
        'field_teaser_text' => $summary,
        'field_channel' => 89,
        'status' => 1,
        'created' => $created,
        
      ]);
      //If article has authors append them to the apropriate field
      if($authors){
        foreach($authors as $author){
          $node->field_authors->appendItem($author);
        }
      }

  
      //Create paragraph entity
      $paragraph = Paragraph::create([
        'type' => 'text',
        'field_text' => array(
            "value"  =>  $body,
            "format" => "full_html"
          ),
        'parent_type' => 'node',
        'parent_id' => $node->id(),
        'status' => 1,
        'parent_field_name' => 'field_paragraphs'
      ]);
      $paragraph->isNew();
      $paragraph->save();	
  
      //Attach paragraph to node
      $node->field_paragraphs = array(
        array(
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        )
      );
      $node->save();
}

function replaceImage($string) {
  //Get start and end of file/media entity in body
	$firstPart = substr($string,strpos($string,'[[{"'));
	$lastPart = substr($firstPart,0,strpos($firstPart,']]')+2);
	//Get fid and run query inside to get attachment
	$fileStart = substr($lastPart,strpos($lastPart,'fid":"')+6);
	$fid = substr($fileStart,0,strpos($fileStart,'"'));
  $database = Database::getConnection('default', 'originDB');
  $query = $database->query("SELECT filename,uri from file_managed where fid='" . $fid . "';");
  $result = $query->fetchAll();
  $filename = $result[0]->filename;
  $image_uri = $result[0]->uri;

  // Create file object from remote URL.
  $image_uri = explode("/",$image_uri,2);
  $image_url = "http://www.oldsite.gr/sites/default/files" . $image_uri[1];
  $data = file_get_contents($image_url);
  $file = file_save_data($data, "public://".$filename , FILE_EXISTS_REPLACE);
  $img_src = $file->createFileUrl();
  //Return the item as image element
	return str_replace($lastPart,"<img src='" . $img_src . "'/>",$string);
}





function getAuthors($entity_id) {
  $database = Database::getConnection('default', 'originDB');
  $query = $database->query("SELECT field_columnist_target_id from field_data_field_columnist where entity_id='" . $entity_id . "'");
  $author_ids = $query->fetchAll();
  //Process each of the authors(they are not users)
  foreach ($author_ids as $key => $author_id) {
    $author_id = $author_id->field_columnist_target_id;
    $authorquery = $database->query("SELECT field_arthrografos_taxinomisi_value from field_data_field_arthrografos_taxinomisi where entity_id = '" . $author_id . "'");
    $author_name = $authorquery->fetchAll();
    $author_name = $author_name[0]->field_arthrografos_taxinomisi_value;
    //If the article has authors...
    if($author_name){
      $term = \Drupal::entityManager()->getStorage('taxonomy_term')->loadByProperties(['name' => $author_name]);
      //...and if the author exists...
      if ($term) {
        dump('term exists');
        //...Just assign his id...
        $authors[] = reset($term)->tid->value;
      }else{
        //...else create term
        dump('term is created with author name:',$author_name);
        $term = Term::create(array(
          'parent' => array(),
          'name' => $author_name,
          'vid' => 'Author',
        ))->save();
        $term = \Drupal::entityManager()->getStorage('taxonomy_term')->loadByProperties(['name' => $author_name]);
        $authors[] = reset($term)->tid->value;
      }
    }
  }
  return $authors;
}