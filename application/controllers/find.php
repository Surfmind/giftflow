<?php
/**
*	Find and search pages routed through this controller.
*
*	@author Brandon Jackson
*	@package Controllers
*/
class Find extends CI_Controller {

	var $data;
	
	var $G;

	/**
	*	@var array
	*/
	var $args = array(
		"q"=>"",
		"type"=>"gift",
		"location"=>"",
		"category_id"=>NULL,
		"radius"=>100,
		"limit"=>20,
		"offset"=>0
	);
	
	function __construct()
	{
		parent::__construct();
		$this->util->config();
		$this->data = $this->util->parse_globals(array(
			"geocode_ip"=>TRUE
		));
		$this->load->library('Search/Good_search');
		$this->load->library('Search/User_search');
		$this->load->library('finder');
	}

	public function index( $type = NULL, $q = NULL)
	{
		Console::logSpeed("Find::index()");
		
		$this->_set_args();
		
		// Run search query if extra parameters, $_GET or $_POST data found
		if(!empty($_GET) || !empty($_POST) || !empty($type))
		{
			Console::logSpeed("Find::starting_query");
		
			$this->_search();
			
			if($this->input->is_ajax_request())
			{
				return $this->util->json($this->data['results_json']);
			}
		}
		
		
		// Page Title
		$this->data['title'] = "Find";
				
		// Type of data being searched
		$this->data['type'] = $this->args["type"];
		
		// Search keyword
		$this->data['keyword'] = $this->args["q"];
		
		// Search args
		$this->data['args'] = $this->args;
		
		//Store searh radius
		$this->data['radius'] = $this->args["radius"];

		// Page can display one of three types of content.
		// 1. Tags
		// 2. Tags + No results message
		// 3. Results & Google Map
		// Cases 1 & 2: Tags / Tags + No Results Message
		if(empty($this->data['results']))
		{
			// Load and instantiate Tag_search library
			$this->load->library('Search/Tag_search');
			$T = new Tag_search;
						
			// Get popular tags
			$this->data['tags'] = $T->popular_tags(array(
				"location"=>$this->data['userdata']['location'],
				"type"=>"gift"
			));
			
			// Set display type
			if(empty($this->data['keyword']))
			{
				$this->data['display'] = 'tags';
			}
			elseif(!empty($this->data['keyword']))
			{
				$this->data['display'] = 'no_results';
			}
		}
		elseif(!empty($this->data['results']))
		{
			$this->data['display'] = 'results';
		}

		// Load category data
		$this->data['categories']= $this->db->order_by("name","ASC")
			->where('parent_category_id',NULL)
			->get('categories')
			->result();
		
		// Load Menu
		$this->data['menu'] = $this->load->view('find/includes/menu', $this->data, TRUE);

		// Load views
		$this->load->view('header',$this->data);
		$this->load->view('find/includes/header',$this->data);
		$this->load->view('find/index', $this->data);
		$this->load->view('footer', $this->data);
		
		Console::logSpeed("Find::index(): done.");
	}
	
	/**
	*	Performs search
	*/
	function _search ()
	{
		Console::logSpeed("Find::_search()");
		
		$this->data['results'] = array();
		
		if($this->args["type"]=="people")
		{
			Console::logSpeed("Find::_search(): starting User_search...");
			$options= array(
				"screen_name"=>$this->args["q"], 
				"first_name"=>$this->args["q"], 
				"last_name"=>$this->args["q"], 
				"bio"=>$this->args["q"],
				"occupation"=>$this->args["q"],
				"location"=>$this->args['location'],
				"radius"=>$this->args['radius'],
				"limit"=>50,
				"order_by"=>"location_distance",
				"sort" => 'ASC'
			);
				
			$US = new User_search;
			$this->data['results'] = $US->find($options);
				
		}
		else
		{
			Console::logSpeed("Find::_search(): starting goods search...");
			
			$GS = new Good_search;
			
			$options = array(
				"location"=>$this->args["location"],
				"keyword"=>$this->args["q"],
				"type"=>$this->args["type"],
				"category_id"=>$this->args["category_id"],
				"order_by"=>$this->args["order_by"],
				"limit"=>100,
				"status"=>"active",
				'sort' =>$this->args['sort']
			);
			
			$results = $GS->find($options);
			$this->data['results'] = $this->factory->goods_ajax($results, $this->args['order_by']);
		}
		
		// Encode results into JSON
		$data = array(
			"center"=>$this->args["location"],
			"total_results"=>count($this->data['results']),
			"results"=>$this->data['results']
		);
		$this->data['results_json'] = json_encode($data);
	}
	
	function _set_args()
	{
		Console::logSpeed("Find::_set_args()");
			
		// Scan 2nd URL segment for useful args
		if(!empty($this->data['segment'][2]))
		{
			$types = array("gift","need","people");

			// Scan for `type` keywords
			if($this->data['segment'][2]=="gifts")
			{
				$this->args["type"] = "gift";
			}
			elseif($this->data['segment'][2]=="needs")
			{
				$this->args["type"] = "need";
			}
			elseif(in_array(strtolower($this->data['segment'][2]),$types))
			{
				$this->args["type"] = strtolower($this->data['segment'][2]);
			}
			
			// If not a type and not index, use as `q`
			elseif($this->data["segment"][2]!="index")
			{
				$this->args["q"] = urldecode($this->data["segment"][2]);
			}
		}
		
		// Scan 3rd URL segment for `q`
		if(!empty($this->data['segment'][3]))
		{
			$this->args["q"] = $this->data["segment"][3];
		}
		
		// Get Data from $_GET and $_POST arrays
		foreach($this->args as $key=>$val)
		{
			if(!empty($_REQUEST[$key]))
			{
				$this->args[$key] = $_REQUEST[$key];
			}
		}
		
		// Set order by clause
		// UI passes a value of either "newest" or "nearby"
		// Search lib requires values of either "G.created" or 
		// "location_distance"
		
		$this->args["order_by"] = "G.created";
		$this->args['sort'] = 'DESC';
		
		// Encode "nearby" as "location_distance" if found
		if(!empty($_REQUEST["order_by"]) && $_REQUEST["order_by"]=="nearby")
		{
			$this->args["order_by"] = "location_distance";
			$this->args['sort'] = 'ASC';
		}
		
		
		// If no location, try to use the userdata's location object
		if(empty($this->args["location"]) && !empty($this->data['userdata']['location']))
		{
			$this->args["location"] = $this->data['userdata']['location'];
		}
		// If location consists only of a string, geocode it
		elseif(!empty($this->args["location"]) && !is_object($this->args["location"]))
		{
			$this->load->library('geo');
			$this->args["location"] = $this->geo->geocode($this->args['location']);
		}
		elseif(empty($this->args["location"]))
		{
			$this->load->library('geo');
			$this->args["location"] = $this->geo->geocode_ip();
		}
		
	}
}
