<?php
/**
 * Product.php
 * 
 * Database management of catalog products
 *
 * @author Jonathan Davis
 * @version 1.0
 * @copyright Ingenesis Limited, 28 March, 2008
 * @package shopp
 * @since 1.0
 * @subpackage products
 **/

require_once("Asset.php");
require_once("Price.php");
require_once("Promotion.php");

class Product extends DatabaseObject {
	static $table = "product";
	var $prices = array();
	var $pricekey = array();
	var $priceid = array();
	var $categories = array();
	var $tags = array();
	var $images = array();
	var $specs = array();
	var $max = array();
	var $min = array();
	var $onsale = false;
	var $freeshipping = false;
	var $outofstock = false;
	var $stock = 0;
	var $options = 0;
	
	function __construct ($id=false,$key=false) {
		$this->init(self::$table);
		if ($this->load($id,$key)) return true;
		return false;
	}
	
	/**
	 * Loads specified relational data associated with the product
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @param array $options List of data to load (prices, images, categories, tags, specs)
	 * @param array $products List of products to load data for
	 * @return void
	 **/
	function load_data ($options=false,&$products=false) {
		global $Shopp;
		$db =& DB::get();

		// Load object schemas on request
		
		$catalogtable = DatabaseObject::tablename(Catalog::$table);

		$Dataset = array();
		if (in_array('prices',$options)) {
			$promotable = DatabaseObject::tablename(Promotion::$table);
			$discounttable = DatabaseObject::tablename(Discount::$table);
			$assettable = DatabaseObject::tablename(ProductDownload::$table);

			$Dataset['prices'] = new Price();
			$Dataset['prices']->_datatypes['promos'] = "MAX(promo.status)";
			$Dataset['prices']->_datatypes['promotions'] = "group_concat(promo.name)";
			$Dataset['prices']->_datatypes['percentoff'] = "SUM(IF (promo.type='Percentage Off',promo.discount,0))";
			$Dataset['prices']->_datatypes['amountoff'] = "SUM(IF (promo.type='Amount Off',promo.discount,0))";
			$Dataset['prices']->_datatypes['freeshipping'] = "SUM(IF (promo.type='Free Shipping',1,0))";
			$Dataset['prices']->_datatypes['buyqty'] = "IF (promo.type='Buy X Get Y Free',promo.buyqty,0)";
			$Dataset['prices']->_datatypes['getqty'] = "IF (promo.type='Buy X Get Y Free',promo.getqty,0)";
			$Dataset['prices']->_datatypes['download'] = "download.id";
			$Dataset['prices']->_datatypes['filename'] = "download.name";
			$Dataset['prices']->_datatypes['filedata'] = "download.value";
		}

		if (in_array('images',$options)) {
			$Dataset['images'] = new ProductImage();
			array_merge($Dataset['images']->_datatypes,$Dataset['images']->_xcols);
		}

		if (in_array('categories',$options)) {
			$Dataset['categories'] = new Category();
			unset($Dataset['categories']->_datatypes['priceranges']);
			unset($Dataset['categories']->_datatypes['specs']);
			unset($Dataset['categories']->_datatypes['options']);
			unset($Dataset['categories']->_datatypes['prices']);
		}

		if (in_array('specs',$options)) $Dataset['specs'] = new Spec();
		if (in_array('tags',$options)) $Dataset['tags'] = new Tag();

		// Determine the maximum columns to allocate
		$maxcols = 0;
		foreach ($Dataset as $set) {
			$cols = count($set->_datatypes);
			if ($cols > $maxcols) $maxcols = $cols;
		}
		
		// Prepare product list depending on single product or entire list
		$ids = array();
		if (isset($products) && is_array($products)) {
			foreach ($products as $product) $ids[] = $product->id;
		} else $ids[0] = $this->id;
		
		// Skip if there are no product ids
		if (empty($ids) || empty($ids[0])) return false;
		
		// Build the mega-query	
		foreach ($Dataset as $rtype => $set) {

			// Allocate generic columns for record data
			$columns = array(); $i = 0;
			foreach ($set->_datatypes as $key => $datatype)
				$columns[] = ((strpos($datatype,'.')!==false)?"$datatype":"{$set->_table}.$key")." AS c".($i++);
			for ($i = $i; $i < $maxcols; $i++) 
				$columns[] = "'' AS c$i";
			
			$cols = join(',',$columns);

			// Build object-specific selects and UNION them
			$where = "";
			if (isset($query)) $query .= " UNION ";
			else $query = "";
			switch($rtype) {
				case "prices":
					foreach ($ids as $id) $where .= ((!empty($where))?" OR ":"")."$set->_table.product=$id";
					$query .= "(SELECT '$set->_table' as dataset,$set->_table.product AS product,'$rtype' AS rtype,'' AS alphaorder,$set->_table.sortorder AS sortorder,$cols FROM $set->_table 
								LEFT JOIN $assettable AS download ON $set->_table.id=download.parent AND download.context='price' AND download.type='download' 
								LEFT JOIN $discounttable AS discount ON discount.product=$set->_table.product AND discount.price=$set->_table.id
								LEFT JOIN $promotable AS promo ON promo.id=discount.promo AND (promo.status='enabled' AND ((UNIX_TIMESTAMP(starts)=1 AND UNIX_TIMESTAMP(ends)=1) OR (UNIX_TIMESTAMP(now()) > UNIX_TIMESTAMP(starts) AND UNIX_TIMESTAMP(now()) < UNIX_TIMESTAMP(ends)) ))
								WHERE $where GROUP BY $set->_table.id)";
					break;
				case "images":
					$ordering = $Shopp->Settings->get('product_image_order');
					if (empty($ordering)) $ordering = "ASC";
					$orderby = $Shopp->Settings->get('product_image_orderby');

					$sortorder = "0";
					if ($orderby == "sortorder" || $orderby == "created") {
						if ($orderby == "created") $orderby = "UNIX_TIMESTAMP(created)";
						switch ($ordering) {
							case "DESC": $sortorder = "$orderby*-1"; break;
							case "RAND": $sortorder = "RAND()"; break;
							default: $sortorder = "$orderby";
						}
					}

					$alphaorder = "''";
					if ($orderby == "name") {
						switch ($ordering) {
							case "DESC": $alphaorder = "$orderby"; break;
							case "RAND": $alphaorder = "RAND()"; break;
							default: $alphaorder = "$orderby";
						}
					}

					foreach ($ids as $id) $where .= ((!empty($where))?" OR ":"")."parent=$id";
					$where = "($where) AND context='product' AND type='image'";
					$query .= "(SELECT '$set->_table' as dataset,parent AS product,'$rtype' AS rtype,$alphaorder AS alphaorder,$sortorder AS sortorder,$cols FROM $set->_table WHERE $where ORDER BY $orderby)";
					break;
				case "specs":
					foreach ($ids as $id) $where .= ((!empty($where))?" OR ":"")."parent=$id AND context='product' AND type='spec'";
					$query .= "(SELECT '$set->_table' as dataset,parent AS product,'$rtype' AS rtype,'' AS alphaorder,sortorder AS sortorder,$cols FROM $set->_table WHERE $where)";
					break;
				case "categories":
					foreach ($ids as $id) $where .= ((!empty($where))?" OR ":"")."catalog.product=$id";
					$where = "($where) AND catalog.category > 0";
					$query .= "(SELECT '$set->_table' as dataset,catalog.product AS product,'$rtype' AS rtype,$set->_table.name AS alphaorder,0 AS sortorder,$cols FROM $catalogtable AS catalog LEFT JOIN $set->_table ON catalog.parent=$set->_table.id AND catalog.type='category' WHERE $where)";
					break;
				case "tags":
					foreach ($ids as $id) $where .= ((!empty($where))?" OR ":"")."catalog.product=$id";
					$where = "($where) AND catalog.tag > 0";
					$query .= "(SELECT '$set->_table' as dataset,catalog.product AS product,'$rtype' AS rtype,$set->_table.name AS alphaorder,0 AS sortorder,$cols FROM $catalogtable AS catalog LEFT JOIN $set->_table ON catalog.parent=$set->_table.id AND type='tag' WHERE $where)";
					break;
			}
		}

		// Add order by columns
		$query .= " ORDER BY sortorder";
		// die($query);
		
		// Execute the query
		$data = $db->query($query,AS_ARRAY);
		
		// Process the results into specific product object data in a product set
		
		foreach ($data as $row) {
			if (is_array($products) && isset($products[$row->product])) 
				$target = $products[$row->product];
			else $target = $this;

			$record = new stdClass(); $i = 0; $name = "";
			foreach ($Dataset[$row->rtype]->_datatypes AS $key => $datatype) {
				$column = 'c'.$i++;
				$record->{$key} = '';
				if ($key == "name") $name = $row->{$column};
				if (!empty($row->{$column})) {
					if (preg_match("/^[sibNaO](?:\:.+?\{.*\}$|\:.+;$|;$)/",$row->{$column}))
						$row->{$column} = unserialize($row->{$column});
					$record->{$key} = $row->{$column};
				}
			}
			
			if ($row->rtype == "images") {
				$image = new ProductImage();
				$image->copydata($record,false,array());
				$image->expopulate();
				$record = $image;
			}
						
			$target->{$row->rtype}[] = $record;
			if (!empty($name)) {
				if (isset($target->{$row->rtype.'key'}[$name]))
					$target->{$row->rtype.'key'}[$name] = array($target->{$row->rtype.'key'}[$name],$record);
				else $target->{$row->rtype.'key'}[$name] = $record;
			}
		}
		
		if (is_array($products)) {
			foreach ($products as $product) if (!empty($product->prices)) $product->pricing();
		} else {
			if (!empty($this->prices)) $this->pricing($options);
		}
		
	} // end load_data()
		
	/**
	 * Aggregates product pricing information
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @param array $options shopp() tag option list
	 * @return void
	 **/
	function pricing ($options = false) {
		global $Shopp;
		
		// Variation range index/properties
		$varranges = array('price' => 'price','saleprice'=>'promoprice');
		
		$variations = ($this->variations == "on");
		$freeshipping = true;
		$this->inventory = false;
		foreach ($this->prices as $i => &$price) {
			// Build secondary lookup table using the combined optionkey
			$this->pricekey[$price->optionkey] = $price;
			
			// Build third lookup table using the price id as the key
			$this->priceid[$price->id] = $price;
			if ($price->type == "N/A" || ($i > 0 && !$variations)) continue;
			
			// Boolean flag for custom product sales
			$price->onsale = false;
			if ($price->sale == "on" && $price->type != "N/A")
				$this->onsale = $price->onsale = true;
			
			$price->stocked = false;
			if ($price->inventory == "on" && $price->type != "N/A") {
				$this->stock += $price->stock;
				$this->inventory = $price->stocked = true;
			}
			
			if ($price->freeshipping == 0) $freeshipping = false;

			if ($price->onsale) $price->promoprice = (float)$price->saleprice;
			else $price->promoprice = (float)$price->price;

			if ((isset($price->promos) && $price->promos == 'enabled')) {
				if ($price->percentoff > 0) {
					$price->promoprice = $price->promoprice - ($price->promoprice * ($price->percentoff/100));
					$this->onsale = $price->onsale = true;
				}
				if ($price->amountoff > 0) {
					$price->promoprice = $price->promoprice - $price->amountoff;
					$this->onsale = $price->onsale = true;;
				}
			}

			// Grab price and saleprice ranges (minimum - maximum)
			if ($price->type != "N/A") {
				if (!$price->price) $price->price = 0;
				
				if ($price->stocked) $varranges['stock'] = 'stock';
				foreach ($varranges as $name => $prop) {
					if (!isset($price->$prop)) continue;
					
					if (!isset($this->min[$name])) $this->min[$name] = $price->$prop;
					else $this->min[$name] = min($this->min[$name],$price->$prop);

					if (!isset($this->max[$name])) $this->max[$name] = $price->$prop;
					else $this->max[$name] = max($this->max[$name],$price->$prop);
				}
			}
			
			// Determine savings ranges
			if ($price->onsale && isset($this->min['price']) && isset($this->min['saleprice'])) {

				if (!isset($this->min['saved'])) {
					$this->min['saved'] = $price->price;
					$this->min['savings'] = 100;
					$this->max['saved'] = $this->max['savings'] = 0;
				}
				
				$this->min['saved'] = min($this->min['saved'],($price->price-$price->promoprice));
				$this->max['saved'] = max($this->max['saved'],($price->price-$price->promoprice));
				
				// Find lowest savings percentage
				if ($this->min['saved'] == ($price->price-$price->promoprice))
					$this->min['savings'] = ($price->promoprice/$price->price)*100;
				if ($this->max['saved'] == ($price->price-$price->promoprice))
					$this->max['savings'] = ($price->promoprice/$price->price)*100;
			}
			
			// Determine weight ranges
			if($price->weight && $price->weight > 0) {
				if(!isset($this->min['weight'])) $this->min['weight'] = $this->max['weight'] = $price->weight;
				$this->min['weight'] = min($this->min['weight'],$price->weight);
				$this->max['weight'] = max($this->max['weight'],$price->weight);
			}

			if (defined('WP_ADMIN') && !isset($options['taxes'])) $options['taxes'] = true;
			if (defined('WP_ADMIN') && value_is_true($options['taxes']) && $price->tax == "on") { 
				$base = $Shopp->Settings->get('base_operations');
				if ($base['vat']) {
					$Taxes = new CartTax();
					$taxrate = $Taxes->rate();

					$price->price += $price->price*$taxrate;
					$price->saleprice += $price->saleprice*$taxrate;
				}
			}
			
		} // end foreach($price)
		
		if ($this->inventory && $this->stock <= 0) $this->outofstock = true;
		if ($freeshipping) $this->freeshipping = true;
	}
	
	/**
	 * Detect if the product is currently published
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @return boolean
	 **/
	function published () {
		return ($this->status == "publish" && mktime() >= $this->publish);
	}
	
	/**
	 * Merges specs with identical names into an array of values
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @return void
	 **/
	function merge_specs () {
		$merged = array();
		foreach ($this->specs as $key => $spec) {
			if (!isset($merged[$spec->name])) $merged[$spec->name] = $spec;
			else {
				if (!is_array($merged[$spec->name]->value)) 
					$merged[$spec->name]->value = array($merged[$spec->name]->value);
				$merged[$spec->name]->value[] = $spec->value;
			}
		}
		$this->specs = $merged;
	}
	
	/**
	 * Saves product category assignments to the catalog
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @param array $updates Updated list of category ids the product is assigned to
	 * @return void
	 **/
	function save_categories ($updates) {
		$db = DB::get();
		
		if (empty($updates)) $updates = array();
		
		$current = array();
		foreach ($this->categories as $category) $current[] = $category->id;

		$added = array_diff($updates,$current);
		$removed = array_diff($current,$updates);

		$table = DatabaseObject::tablename(Catalog::$table);

		if (!empty($added)) {
			foreach ($added as $id) {
				if (empty($id)) continue;
				$db->query("INSERT $table SET parent='$id',type='category',product='$this->id',created=now(),modified=now()");
			}
		}
		
		if (!empty($removed)) {
			foreach ($removed as $id) {
				if (empty($id)) continue;
				$db->query("DELETE LOW_PRIORITY FROM $table WHERE parent='$id' AND type='category' AND product='$this->id'"); 
			}
			
		}
		
	}

	function save_tags ($updates) {
		$db = DB::get();
		
		if (empty($updates)) $updates = array();
		$updates = stripslashes_deep($updates);
		
		$current = array();
		foreach ($this->tags as $tag) $current[] = $tag->name;
		
		$added = array_diff($updates,$current);
		$removed = array_diff($current,$updates);

		if (!empty($added)) {
			$catalog = DatabaseObject::tablename(Catalog::$table);
			$tagtable = DatabaseObject::tablename(Tag::$table);
			$where = "";
			foreach ($added as $tag) $where .= ($where == ""?"":" OR ")."name='".$db->escape($tag)."'";
			$results = $db->query("SELECT id,name FROM $tagtable WHERE $where",AS_ARRAY);
			$exists = array();
			foreach ($results as $tag) $exists[$tag->id] = $tag->name;

			foreach ($added as $tag) {
				if (empty($tag)) continue; // No empty tags
				$tagid = array_search($tag,$exists);

				if (!$tagid) {
					$Tag = new Tag();
					$Tag->name = $tag;
					$Tag->save();
					$tagid = $Tag->id;
				}

				if (!empty($tagid))
					$db->query("INSERT $catalog SET tag='$tagid',product='$this->id',created=now(),modified=now()");
					
			}
		}

		if (!empty($removed)) {
			$catalog = DatabaseObject::tablename(Catalog::$table);
			foreach ($removed as $tag) {
				$Tag = new Tag($tag,'name');
				if (!empty($Tag->id))
					$db->query("DELETE LOW_PRIORITY FROM $catalog WHERE tag='$Tag->id' AND product='$this->id'"); 
			}
		}

	}
			
	/**
	 * optionkey
	 * There is no Zul only XOR! */
	function optionkey ($ids=array(),$deprecated=false) {
		if ($deprecated) $factor = 101;
		else $factor = 7001;
		if (empty($ids)) return 0;
		$key = 0;
		foreach ($ids as $set => $id) 
			$key = $key ^ ($id*$factor);
		return $key;
	}
	
	/**
	 * save_imageorder()
	 * Updates the sortorder of image assets (source, featured and thumbnails)
	 * based on the provided array of image ids */
	function save_imageorder ($ordering) {
		$db = DB::get();
		$table = DatabaseObject::tablename(ProductImage::$table);
		foreach ($ordering as $i => $id)
			$db->query("UPDATE LOW_PRIORITY $table SET sortorder='$i' WHERE (id='$id' AND parent='$this->id' AND context='product' AND type='image')");
		return true;
	}
	
	/**
	 * link_images()
	 * Updates the product id of the images to link to the product 
	 * when the product being saved is new (has no previous id assigned) */
	function link_images ($images) {
		$db = DB::get();
		$table = DatabaseObject::tablename(ProductImage::$table);
				
		$set = "id=".join('OR id=',$images);
		
		if (empty($query)) return false;
		else $query = "UPDATE $table SET parent='$this->id',context='product' WHERE ".$set;
		
		$db->query($query);
		
		return true;
	}
	
	/**
	 * update_images()
	 * Updates the image details for all cached images */
	function update_images ($images) {
		if (!is_array($images)) return false;
		
		foreach ($images as $img) {
			$Image = new ProductImage($img['id']);
			$Image->title = $img['title'];
			$Image->alt = $img['alt'];
			$Image->save();
		}
		
		return true;
	}
	
	
	/**
	 * delete_images()
	 * Delete provided array of image ids, removing the source image and
	 * all related images (small and thumbnails) */
	function delete_images ($images) {
		$db = &DB::get();
		$imagetable = DatabaseObject::tablename(ProductImage::$table);
		$imagesets = "";
		foreach ($images as $image) {
			$imagesets .= (!empty($imagesets)?" OR ":"");
			$imagesets .= "((context='product' AND parent='$this->id' AND id='$image') OR (context='image' AND parent='$image'))";
		}
		if (!empty($imagesets))
			$db->query("DELETE FROM $imagetable WHERE type='image' AND ($imagesets)");
		return true;
	}
	
	/**
	 * Deletes the record associated with this object */
	function delete () {
		$db = DB::get();
		$id = $this->{$this->_key};
		if (empty($id)) return false;
		
		// Delete from categories
		$table = DatabaseObject::tablename(Catalog::$table);
		$db->query("DELETE LOW_PRIORITY FROM $table WHERE product='$id'");

		// Delete prices
		$table = DatabaseObject::tablename(Price::$table);
		$db->query("DELETE LOW_PRIORITY FROM $table WHERE product='$id'");

		// Delete images/files
		$table = DatabaseObject::tablename(ProductImage::$table);

		// Delete images
		$images = array();
		$src = $db->query("SELECT id FROM $table WHERE parent='$id' AND context='product' AND type='image'",AS_ARRAY);
		foreach ($src as $img) $images[] = $img->id;
		$this->delete_images($images);
		
		// Delete product meta (specs, images, downloads)
		$table = DatabaseObject::tablename(MetaObject::$table);
		$db->query("DELETE LOW_PRIORITY FROM $table WHERE parent='$id' AND context='product'");

		// Delete record
		$db->query("DELETE FROM $this->_table WHERE $this->_key='$id'");

	}
	
	function duplicate () {
		$db =& DB::get();
		
		$this->load_data(array('prices','specs','categories','tags','images','taxes'=>'false'));
		$this->id = '';
		$this->name = $this->name.' '.__('copy','Shopp');
		$this->slug = sanitize_title_with_dashes($this->name);

		// Check for an existing product slug
		$existing = $db->query("SELECT slug FROM $this->_table WHERE slug='$this->slug' LIMIT 1");
		if ($existing) {
			$suffix = 2;
			while($existing) {
				$altslug = substr($this->slug, 0, 200-(strlen($suffix)+1)). "-$suffix";
				$existing = $db->query("SELECT slug FROM $this->_table WHERE slug='$altslug' LIMIT 1");
				$suffix++;
			}
			$this->slug = $altslug;
		}
		$this->created = '';
		$this->modified = '';
		
		$this->save();
		
		// Copy prices
		foreach ($this->prices as $price) {
			$Price = new Price();
			$Price->updates($price,array('id','product','created','modified'));
			$Price->product = $this->id;
			$Price->save();
		}
		
		// Copy sepcs
		foreach ($this->specs as $spec) {
			$Spec = new Spec();
			$Spec->updates($spec,array('id','product','created','modified'));
			$Spec->product = $this->id;
			$Spec->save();
		}
		
		// Copy categories
		$categories = array();
		foreach ($this->categories as $category) $categories[] = $category->id;
		$this->categories = array();
		$this->save_categories($categories);

		// Copy tags
		$taglist = array();
		foreach ($this->tags as $tag) $taglist[] = $tag->name;
		$this->tags = array();
		$this->save_tags($taglist);

		// Copy product images
		foreach ($this->images as $ProductImage) {
			$Image = new ProductImage();
			$Image->updates($ProductImage,array('id','product','created','modified'));
			$Image->product = $this->id;
			$Image->save();
		}
				
	}
	
	function tag ($property,$options=array()) {
		global $Shopp;
		add_filter('shopp_product_name','convert_chars');
		add_filter('shopp_product_summary','convert_chars');

		add_filter('shopp_product_description', 'wptexturize');
		add_filter('shopp_product_description', 'convert_chars');
		add_filter('shopp_product_description', 'wpautop');
		add_filter('shopp_product_description', 'do_shortcode', 11); // AFTER wpautop()	

		add_filter('shopp_product_spec', 'wptexturize');
		add_filter('shopp_product_spec', 'convert_chars');
		add_filter('shopp_product_spec', 'do_shortcode', 11); // AFTER wpautop()	
				
		switch ($property) {
			case "link": 
			case "url": 
				if (SHOPP_PERMALINKS) $url = esc_url(user_trailingslashit($Shopp->canonuri.urldecode($this->slug)));
				else $url = add_query_arg('shopp_pid',$this->id,$Shopp->canonuri);
				return $url;
				break;
			case "found": 
				if (empty($this->id)) return false;
				$load = array('prices','images','specs');
				if (isset($options['load'])) $load = explode(",",$options['load']);
				$this->load_data($load);
				return true;
				break;
			case "relevance": return (string)$this->score; break;
			case "id": return $this->id; break;
			case "name": return apply_filters('shopp_product_name',$this->name); break;
			case "slug": return $this->slug; break;
			case "summary": return apply_filters('shopp_product_summary',$this->summary); break;
			case "description": 
				return apply_filters('shopp_product_description',$this->description);
			case "isfeatured": 
			case "is-featured":
				return ($this->featured == "on"); break;
			case "price":
				if (empty($this->prices)) $this->load_data(array('prices'));

				if (!isset($options['taxes'])) $options['taxes'] = null;
				else $options['taxes'] = value_is_true($options['taxes']);
			
				if (count($this->options) > 0) {
					$taxrate = shopp_taxrate($options['taxes']);
					if ($this->min['price'] == $this->max['price'])
						return money($this->min['price'] + ($this->min['price']*$taxrate));
					else {
						if (!empty($options['starting'])) return $options['starting']." ".money($this->min['price']+($this->min['price']*$taxrate));
						return money($this->min['price']+($this->min['price']*$taxrate))." &mdash; ".money($this->max['price'] + ($this->max['price']*$taxrate));
					}
				} else {
					$taxrate = shopp_taxrate($options['taxes'],$this->prices[0]->tax);
					return money($this->prices[0]->price + ($this->prices[0]->price*$taxrate));
				}
				break;
			case "weight":
				if(empty($this->prices)) $this->load_data(array('prices'));
				$unit = (isset($options['units']) && !value_is_true($options['units'])? 
					false : $Shopp->Settings->get('weight_unit'));
				if(!isset($this->min['weight'])) return false;
				
				$string = ($this->min['weight'] == $this->max['weight']) ? 
					round($this->min['weight'],3) :  
					round($this->min['weight'],3) . " - " . round($this->max['weight'],3);
				$string .= ($unit) ? " $unit" : "";
				return $string;
				break;
			case "onsale":
				if (empty($this->prices)) $this->load_data(array('prices'));
				if (empty($this->prices)) return false;
				return $this->onsale;

				$sale = false;
				if (count($this->prices) > 1) {
					foreach($this->prices as $pricetag) 
						if (isset($pricetag->onsale) && $pricetag->onsale == "on") $sale = true;
					return $sale;
				} else return ($this->prices[0]->onsale == "on")?true:false;
				break;
			case "saleprice":
				if (empty($this->prices)) $this->load_data(array('prices'));
				if (!isset($options['taxes'])) $options['taxes'] = null;
				else $options['taxes'] = value_is_true($options['taxes']);
				$pricetag = 'price';

				if ($this->onsale) $pricetag = 'saleprice';
				if (count($this->options) > 0) {
					$taxrate = shopp_taxrate($options['taxes']);
					if ($this->min[$pricetag] == $this->max[$pricetag])
						return money($this->min[$pricetag]+($this->min[$pricetag]*$taxrate)); // No price range
					else {
						if (!empty($options['starting'])) return $options['starting']." ".money($this->min[$pricetag]+($this->min[$pricetag]*$taxrate));
						return money($this->min[$pricetag]+($this->min[$pricetag]*$taxrate))." &mdash; ".money($this->max[$pricetag]+($this->max[$pricetag]*$taxrate));
					}
				} else {
					$taxrate = shopp_taxrate($options['taxes'],$this->prices[0]->tax);
					return money($this->prices[0]->promoprice+($this->prices[0]->promoprice*$taxrate));
				}
				break;
			case "has-savings": return ($this->onsale && $this->min['saved'] > 0)?true:false; break;
			case "savings":
				if (empty($this->prices)) $this->load_data(array('prices'));
				if (!isset($options['taxes'])) $options['taxes'] = null;

				$taxrate = shopp_taxrate($options['taxes']);

				if (!isset($options['show'])) $options['show'] = '';
				if ($options['show'] == "%" || $options['show'] == "percent") {
					if ($this->options > 1) {
						if (round($this->min['savings']) == round($this->max['savings']))
							return percentage($this->min['savings'],array('precision' => 0)); // No price range
						else return percentage($this->min['savings'],array('precision' => 0))." &mdash; ".percentage($this->max['savings'],array('precision' => 0));
					} else return percentage($this->max['savings'],array('precision' => 0));
				} else {
					if ($this->options > 1) {
						if ($this->min['saved'] == $this->max['saved'])
							return money($this->min['saved']+($this->min['saved']*$taxrate)); // No price range
						else return money($this->min['saved']+($this->min['saved']*$taxrate))." &mdash; ".money($this->max['saved']+($this->max['saved']*$taxrate));
					} else return money($this->max['saved']+($this->max['saved']*$taxrate));
				}
				break;
			case "freeshipping":
				if (empty($this->prices)) $this->load_data(array('prices'));
				return $this->freeshipping;
			case "thumbnail":
				if (empty($this->images)) $this->load_data(array('images'));
				if (empty($options['class'])) $options['class'] = '';
				else $options['class'] = ' class="'.$options['class'].'"';

				if (count($this->images) > 0) {
					$img = current($this->images);
					
					$thumbwidth = $Shopp->Settings->get('gallery_thumbnail_width');
					$thumbheight = $Shopp->Settings->get('gallery_thumbnail_height');
					$width = (isset($options['width']))?$options['width']:$thumbwidth;
					$height = (isset($options['height']))?$options['height']:$thumbheight;
					$scale = empty($options['fit'])?false:array_search($options['fit']);
					$sharpen = empty($options['sharpen'])?false:min($options['sharpen'],$img->_sharpen);
					$quality = empty($options['quality'])?false:min($options['quality'],$img->_quality);
					$fill = empty($options['bg'])?false:hexdec(ltrim($options['bg'],'#'));
					$scaled = $img->scaled($width,$height,$scale);

					$alt = empty($options['alt'])?$img->alt:$options['alt'];
					$title = empty($options['title'])?$img->title:$options['title'];
					$title = empty($title)?'':' title="'.esc_attr($title).'"';
					$class = isset($options['class'])?' class="'.esc_attr($options['class']).'"':'';

					if (!empty($options['title'])) $title = ' title="'.esc_attr($options['title']).'"';
					$alt = esc_attr(!empty($img->alt)?$img->alt:$this->name);
					return '<img src="'.add_query_string($img->resizing($width,$height,$scale,$sharpen,$quality,$fill),$Shopp->imguri.$img->id).'"'.$title.' alt="'.$alt.'" width="'.$scaled['width'].'" height="'.$scaled['height'].'" '.$options['class'].' />'; break;
				} else return "";
				break;
			case "hasimages": 
			case "has-images": 
				if (empty($this->images)) $this->load_data(array('images'));
				return (!empty($this->images));
				break;
			case "images":
				if (!$this->images) return false;
				if (!isset($this->_images_loop)) {
					reset($this->images);
					$this->_images_loop = true;
				} else next($this->images);

				if (current($this->images) !== false) return true;
				else {
					unset($this->_images_loop);
					return false;
				}
				break;
			case "image":			
				$img = current($this->images);
				if (isset($options['property'])) {
					switch (strtolower($options['property'])) {
						case "url": return $img->uri;
						case "width": return $img->width;
						case "height": return $img->height;
						case "title": return esc_attr($img->title);
						case "alt": return esc_attr($img->alt);
						default: return $img->id;
					}
				}
				$thumbwidth = $Shopp->Settings->get('gallery_thumbnail_width');
				$thumbheight = $Shopp->Settings->get('gallery_thumbnail_height');
				$width = (isset($options['width']))?$options['width']:$thumbwidth;
				$height = (isset($options['height']))?$options['height']:$thumbheight;
				$fit = empty($options['fit'])?false:array_search($options['fit'],$img->_scaling);
				$sharpen = empty($options['sharpen'])?false:min($options['sharpen'],$img->_sharpen);
				$quality = empty($options['quality'])?false:min($options['quality'],$img->_quality);
				$fill = empty($options['bg'])?false:hexdec(ltrim($options['bg'],'#'));
				$scaled = $img->scaled($width,$height,$fit);

				$alt = empty($options['alt'])?$img->alt:$options['alt'];
				$title = empty($options['title'])?$img->title:$options['title'];
				$title = empty($title)?'':' title="'.esc_attr($title).'"';
				$class = isset($options['class'])?' class="'.esc_attr($options['class']).'"':'';

				$string = "";
				if (!isset($options['zoomfx'])) $options['zoomfx'] = "shopp-zoom";
				if (!empty($options['zoom'])) $string .= '<a href="'.$Shopp->imguri.$img->id.'/image.jpg" class="'.$options['zoomfx'].'" rel="product-gallery">';
				$string .= '<img src="'.add_query_string($img->resizing($width,$height,$fit,$sharpen,$quality,$fill),$Shopp->imguri.$img->id).'"'.$title.' alt="'.$alt.'" width="'.$scaled['width'].'" height="'.$scaled['height'].'" '.$class.' />';
				if (!empty($options['zoom'])) $string .= "</a>";
				return $string;
				break;
			case "gallery":
				if (empty($this->images)) $this->load_data(array('images'));
				if (empty($this->images)) return false;
				
				$preview_width = $Shopp->Settings->get('gallery_small_width');
				$preview_height = $Shopp->Settings->get('gallery_small_height');

				if (!empty($options['p.size'])) {
					$preview_width = $options['p.size'];
					$preview_height = $options['p.size'];
				}
				$width = (isset($options['p.width']))?$options['p.width']:$preview_width;
				$height = (isset($options['p.height']))?$options['p.height']:$preview_height;
				
				if (!isset($options['zoomfx'])) $options['zoomfx'] = "shopp-zoom";
				if (!isset($options['preview'])) $options['preview'] = "click";
				
				$previews = '<ul class="previews">';
				$firstPreview = true;
				
				foreach ($this->images as $img) {
					$scale = empty($options['p.fit'])?false:array_search($options['p.fit']);
					$sharpen = empty($options['p.sharpen'])?false:min($options['p.sharpen'],$img->_sharpen);
					$quality = empty($options['p.quality'])?false:min($options['p.quality'],$img->_quality);
					$fill = empty($options['p.bg'])?false:hexdec(ltrim($options['p.bg'],'#'));
					$scaled = $img->scaled($width,$height,$scale);
					if ($firstPreview) {
						$previews .= '<li id="preview-fill"'.(($firstPreview)?' class="fill"':'').'>';
						$previews .= '<img src="'.$Shopp->uri.'/core/ui/icons/clear.png'.'" alt=" " width="'.$width.'" height="'.$height.'" />';
						$previews .= '</li>';
					}
					$title = !empty($img->title)?' title="'.esc_attr($img->title).'"':'';
					$alt = esc_attr(!empty($img->alt)?$img->alt:$img->filename);
					$rel = (isset($options['rel']) && $options['rel'])?' rel="gallery_product_'.$this->id.'"':'';
					
					
					$previews .= '<li id="preview-'.$img->id.'"'.(($firstPreview)?' class="active"':'').'>';
					$previews .= '<a href="'.$Shopp->imguri.$img->id.'/image.jpg" class="gallery product_'.$this->id.' '.$options['zoomfx'].'"'.$rel.'>';
					$previews .= '<img src="'.add_query_string($img->resizing($width,$height,$scale,$sharpen,$quality,$fill),$Shopp->imguri.$img->id).'"'.$title.' alt="'.$alt.'" width="'.$scaled['width'].'" height="'.$scaled['height'].'" />';
					$previews .= '</a>';
					$previews .= '</li>';
					$firstPreview = false;
				}
				$previews .= '</ul>';

				$thumbs = "";
				if (count($this->images) > 1) {
					$thumbsize = 32;
					if (isset($options['thumbsize'])) $thumbsize = $options['thumbsize'];
					$thumbwidth = $thumbsize;
					$thumbheight = $thumbsize;

					$width = (isset($options['thumbwidth']))?$options['thumbwidth']:$thumbwidth;
					$height = (isset($options['thumbheight']))?$options['thumbheight']:$thumbheight;

					$firstThumb = true;
					$thumbs = '<ul class="thumbnails">';
					foreach ($this->images as $img) {
						$scale = empty($options['thumbfit'])?false:array_search($options['thumbfit']);
						$sharpen = empty($options['thumbsharpen'])?false:min($options['thumbsharpen'],$img->_sharpen);
						$quality = empty($options['thumbquality'])?false:min($options['thumbquality'],$img->_quality);
						$fill = empty($options['thumbbg'])?false:hexdec(ltrim($options['thumbbg'],'#'));
						// $scaled = $img->scaled($width,$height,$scale);
						$scaled = $img->scaled($thumbwidth,$thumbheight);

						$title = !empty($img->title)?' title="'.esc_attr($img->title).'"':'';
						$alt = esc_attr(!empty($img->alt)?$img->alt:$img->name);

						$thumbs .= '<li id="thumbnail-'.$img->id.'" class="preview-'.$img->id.(($firstThumb)?' first':' test').'">';
						$thumbs .= '<img src="'.add_query_string($img->resizing($thumbwidth,$thumbheight,$scale,$sharpen,$quality,$fill),$Shopp->imguri.$img->id).'"'.$title.' alt="'.$alt.'" width="'.$scaled['width'].'" height="'.$scaled['height'].'" />';
						$thumbs .= '</li>';
						$firstThumb = false;						
					}
					$thumbs .= '</ul>';
				}

				$result = '<div id="gallery-'.$this->id.'" class="gallery">'.$previews.$thumbs.'</div>';
				
				$script = 'ShoppGallery("#gallery-'.$this->id.'","'.$options['preview'].'");';
				add_storefrontjs($script);
				
				return $result;
				break;
			case "has-categories": 
				if (empty($this->categories)) $this->load_data(array('categories'));
				if (count($this->categories) > 0) return true; else return false; break;
			case "categories":			
				if (!isset($this->_categories_loop)) {
					reset($this->categories);
					$this->_categories_loop = true;
				} else next($this->categories);

				if (current($this->categories) !== false) return true;
				else {
					unset($this->_categories_loop);
					return false;
				}
				break;
			case "in-category": 
				if (empty($this->categories)) $this->load_data(array('categories'));
				if (isset($options['id'])) $field = "id";
				if (isset($options['name'])) $field = "name";
				if (isset($options['slug'])) $field = "slug";
				foreach ($this->categories as $category)
					if ($category->{$field} == $options[$field]) return true;
				return false;
			case "category":
				$category = current($this->categories);
				if (isset($options['show'])) {
					if ($options['show'] == "id") return $category->id;
					if ($options['show'] == "slug") return $category->slug;
				}
				return $category->name;
				break;
			case "hastags": 
			case "has-tags": 
				if (empty($this->tags)) $this->load_data(array('tags'));
				if (count($this->tags) > 0) return true; else return false; break;
			case "tags":
				if (!isset($this->_tags_loop)) {
					reset($this->tags);
					$this->_tags_loop = true;
				} else next($this->tags);

				if (current($this->tags) !== false) return true;
				else {
					unset($this->_tags_loop);
					return false;
				}
				break;
			case "tagged": 
				if (empty($this->tags)) $this->load_data(array('tags'));
				if (isset($options['id'])) $field = "id";
				if (isset($options['name'])) $field = "name";
				foreach ($this->tags as $tag)
					if ($tag->{$field} == $options[$field]) return true;
				return false;
			case "tag":
				$tag = current($this->tags);
				if (isset($options['show'])) {
					if ($options['show'] == "id") return $tag->id;
				}
				return $tag->name;
				break;
			case "hasspecs": 
			case "has-specs": 
				if (empty($this->specs)) $this->load_data(array('specs'));
				if (count($this->specs) > 0) {
					$this->merge_specs();
					return true;
				} else return false; break;
			case "specs":			
				if (!isset($this->_specs_loop)) {
					reset($this->specs);
					$this->_specs_loop = true;
				} else next($this->specs);
				
				if (current($this->specs) !== false) return true;
				else {
					unset($this->_specs_loop);
					return false;
				}
				break;
			case "spec":
				$string = "";
				$separator = ": ";
				$delimiter = ", ";
				if (isset($options['separator'])) $separator = $options['separator'];
				if (isset($options['delimiter'])) $separator = $options['delimiter'];

				$spec = current($this->specs);
				if (is_array($spec->value)) $spec->value = join($delimiter,$spec->value);
				
				if (isset($options['name']) 
					&& !empty($options['name']) 
					&& isset($this->specskey[$options['name']])) {
						$spec = $this->specskey[$options['name']];
						if (is_array($spec)) {
							if (isset($options['index'])) {
								foreach ($spec as $index => $entry) 
									if ($index+1 == $options['index']) 
										$content = $entry->value;
							} else {
								foreach ($spec as $entry) $contents[] = $entry->value;
								$content = join($delimiter,$contents);
							}
						} else $content = $spec->content;
					$string = apply_filters('shopp_product_spec',$content);
					return $string;
				}
				
				if (isset($options['name']) && isset($options['content']))
					$string = "{$spec->name}{$separator}".apply_filters('shopp_product_spec',$spec->value);
				elseif (isset($options['name'])) $string = $spec->name;
				elseif (isset($options['content'])) $string = apply_filters('shopp_product_spec',$spec->value);
				else $string = "{$spec->name}{$separator}".apply_filters('shopp_product_spec',$spec->value);
				return $string;
				break;
			case "has-variations":
				return ($this->variations == "on" && (!empty($this->options['v']) || !empty($this->options))); break;
			case "variations":
				
				$string = "";

				if (!isset($options['mode'])) {
					if (!isset($this->_prices_loop)) {
						reset($this->prices);
						$this->_prices_loop = true;
					} else next($this->prices);
					$price = current($this->prices);

					if ($price && ($price->type == 'N/A' || $price->context != 'variation'))
						next($this->prices);
						
					if (current($this->prices) !== false) return true;
					else {
						unset($this->_prices_loop);
						return false;
					}
					return true;
				}

				if ($this->outofstock) return false; // Completely out of stock, hide menus
				if (!isset($options['taxes'])) $options['taxes'] = null;
				
				$defaults = array(
					'defaults' => '',
					'disabled' => 'show',
					'before_menu' => '',
					'after_menu' => '',
					'taxes' => false
					);
					
				$options = array_merge($defaults,$options);

				if (!isset($options['label'])) $options['label'] = "on";
				if (!isset($options['required'])) $options['required'] = __('You must select the options for this item before you can add it to your shopping cart.','Shopp');
				if ($options['mode'] == "single") {
					if (!empty($options['before_menu'])) $string .= $options['before_menu']."\n";
					if (value_is_true($options['label'])) $string .= '<label for="product-options'.$this->id.'">'. __('Options').': </label> '."\n";

					$string .= '<select name="products['.$this->id.'][price]" id="product-options'.$this->id.'">';
					if (!empty($options['defaults'])) $string .= '<option value="">'.$options['defaults'].'</option>'."\n";

					foreach ($this->prices as $pricetag) {
						if ($pricetag->context != "variation") continue;
						
						$taxrate = shopp_taxrate($options['taxes'],$pricetag->tax);
						$currently = ($pricetag->sale == "on")?$pricetag->promoprice:$pricetag->price;
						$disabled = ($pricetag->inventory == "on" && $pricetag->stock == 0)?' disabled="disabled"':'';

						$price = '  ('.money($currently).')';
						if ($pricetag->type != "N/A")
							$string .= '<option value="'.$pricetag->id.'"'.$disabled.'>'.$pricetag->label.$price.'</option>'."\n";
					}

					$string .= '</select>';
					if (!empty($options['after_menu'])) $string .= $options['after_menu']."\n";

				} else {
					if (!isset($this->options)) return;

					$menuoptions = $this->options;
					if (!empty($this->options['v'])) $menuoptions = $this->options['v'];
					
					$baseop = $Shopp->Settings->get('base_operations');
					$precision = $baseop['currency']['format']['precision'];

					$taxrate = shopp_taxrate($options['taxes'],true);
					$pricekeys = array();
					foreach ($this->pricekey as $key => $pricing) {
						$filter = array('');
						$_ = new StdClass();
						$_->p = number_format((isset($pricing->onsale) 
									&& $pricing->onsale == "on")?(float)$pricing->promoprice:(float)$pricing->price,$precision);
						$_->i = ($pricing->inventory == "on")?true:false;
						$_->s = ($pricing->inventory == "on")?$pricing->stock:false;
						$_->t = $pricing->type;
						$pricekeys[$key] = $_;
					}
					
					ob_start();
	?>options_default = <?php echo (!empty($options['defaults']))?'true':'false'; ?>;
	options_required = "<?php echo $options['required']; ?>";
	pricetags[<?php echo $this->id; ?>] = {};
	pricetags[<?php echo $this->id; ?>]['pricing'] = <?php echo json_encode($pricekeys); ?>;
	pricetags[<?php echo $this->id; ?>]['menu'] = new ProductOptionsMenus('select<?php if (!empty($Shopp->Category->slug)) echo ".category-".$Shopp->Category->slug; ?>.product<?php echo $this->id; ?>',<?php echo ($options['disabled'] == "hide")?"true":"false"; ?>,pricetags[<?php echo $this->id; ?>]['pricing'],<?php echo empty($taxrate)?'0':$taxrate; ?>);<?php
					$script = ob_get_contents();
					ob_end_clean();
					add_storefrontjs($script,true);
					
					foreach ($menuoptions as $id => $menu) {
						if (!empty($options['before_menu'])) $string .= $options['before_menu']."\n";
						if (value_is_true($options['label'])) $string .= '<label for="options-'.$menu['id'].'">'.$menu['name'].'</label> '."\n";
						$category_class = isset($Shopp->Category->slug)?'category-'.$Shopp->Category->slug:'';
						$string .= '<select name="products['.$this->id.'][options][]" class="'.$category_class.' product'.$this->id.' options" id="options-'.$menu['id'].'">';
						if (!empty($options['defaults'])) $string .= '<option value="">'.$options['defaults'].'</option>'."\n";
						foreach ($menu['options'] as $key => $option)
							$string .= '<option value="'.$option['id'].'">'.$option['name'].'</option>'."\n";

						$string .= '</select>';
					}
					if (!empty($options['after_menu'])) $string .= $options['after_menu']."\n";
				}

				return $string;
				break;
			case "variation":
				$variation = current($this->prices);
				if (!isset($options['taxes'])) $options['taxes'] = null;
				else $options['taxes'] = value_is_true($options['taxes']);
				$taxrate = shopp_taxrate($options['taxes'],$variation->tax);
				
				$weightunit = (isset($options['units']) && !value_is_true($options['units']) ) ? false : $Shopp->Settings->get('weight_unit');
				
				$string = '';
				if (array_key_exists('id',$options)) $string .= $variation->id;
				if (array_key_exists('label',$options)) $string .= $variation->label;
				if (array_key_exists('type',$options)) $string .= $variation->type;
				if (array_key_exists('sku',$options)) $string .= $variation->sku;
				if (array_key_exists('price',$options)) $string .= money($variation->price+($variation->price*$taxrate));
				if (array_key_exists('saleprice',$options)) $string .= money($variation->saleprice+($variation->saleprice*$taxrate));
				if (array_key_exists('stock',$options)) $string .= $variation->stock;
				if (array_key_exists('weight',$options)) $string .= round($variation->weight, 3) . ($weightunit ? " $weightunit" : false);
				if (array_key_exists('shipfee',$options)) $string .= money(floatvalue($variation->shipfee));
				if (array_key_exists('sale',$options)) return ($variation->sale == "on");
				if (array_key_exists('shipping',$options)) return ($variation->shipping == "on");
				if (array_key_exists('tax',$options)) return ($variation->tax == "on");
				if (array_key_exists('inventory',$options)) return ($variation->inventory == "on");
				return $string;
				break;
			case "has-addons":
				return ($this->addons == "on" && !empty($this->options['a'])); break;
				break;
			case "addons":

				$string = "";

				if (!isset($options['mode'])) {
					if (!$this->priceloop) {
						reset($this->prices);
						$this->priceloop = true;
					} else next($this->prices);
					$thisprice = current($this->prices);

					if ($thisprice && $thisprice->type == "N/A")
						next($this->prices);

					if ($thisprice && $thisprice->context != "addon")
						next($this->prices);

					if (current($this->prices) !== false) return true;
					else {
						$this->priceloop = false;
						return false;
					}
					return true;
				}

				if ($this->outofstock) return false; // Completely out of stock, hide menus
				if (!isset($options['taxes'])) $options['taxes'] = null;

				$defaults = array(
					'defaults' => '',
					'disabled' => 'show',
					'before_menu' => '',
					'after_menu' => ''
					);

				$options = array_merge($defaults,$options);

				if (!isset($options['label'])) $options['label'] = "on";
				if (!isset($options['required'])) $options['required'] = __('You must select the options for this item before you can add it to your shopping cart.','Shopp');
				if ($options['mode'] == "single") {
					if (!empty($options['before_menu'])) $string .= $options['before_menu']."\n";
					if (value_is_true($options['label'])) $string .= '<label for="product-options'.$this->id.'">'. __('Options').': </label> '."\n";

					$string .= '<select name="products['.$this->id.'][price]" id="product-options'.$this->id.'">';
					if (!empty($options['defaults'])) $string .= '<option value="">'.$options['defaults'].'</option>'."\n";

					foreach ($this->prices as $pricetag) {
						if ($pricetag->context != "addon") continue;

						$taxrate = shopp_taxrate($options['taxes'],$pricetag->tax);
						$currently = ($pricetag->sale == "on")?$pricetag->promoprice:$pricetag->price;
						$disabled = ($pricetag->inventory == "on" && $pricetag->stock == 0)?' disabled="disabled"':'';

						$price = '  ('.money($currently).')';
						if ($pricetag->type != "N/A")
							$string .= '<option value="'.$pricetag->id.'"'.$disabled.'>'.$pricetag->label.$price.'</option>'."\n";
					}

					$string .= '</select>';
					if (!empty($options['after_menu'])) $string .= $options['after_menu']."\n";

				} else {
					if (!isset($this->options['a'])) return;

					$taxrate = shopp_taxrate($options['taxes'],true);
					$options['after_menu'] = $script.$options['after_menu'];

					// Index addon prices by option
					$pricing = array();
					foreach ($this->prices as $pricetag) {
						if ($pricetag->context != "addon") continue;
						$pricing[$pricetag->options] = $pricetag;
					}
					
					foreach ($this->options['a'] as $id => $menu) {
						if (!empty($options['before_menu'])) $string .= $options['before_menu']."\n";
						if (value_is_true($options['label'])) $string .= '<label for="options-'.$menu['id'].'">'.$menu['name'].'</label> '."\n";
						$category_class = isset($Shopp->Category->slug)?'category-'.$Shopp->Category->slug:'';
						$string .= '<select name="products['.$this->id.'][addons][]" class="'.$category_class.' product'.$this->id.' options" id="options-'.$menu['id'].'">';
						if (!empty($options['defaults'])) $string .= '<option value="">'.$options['defaults'].'</option>'."\n";
						foreach ($menu['options'] as $key => $option) {
							
							$pricetag = $pricing[$option['id']];
							$taxrate = shopp_taxrate($options['taxes'],$pricetag->tax);
							$currently = ($pricetag->sale == "on")?$pricetag->promoprice:$pricetag->price;
							$string .= '<option value="'.$option['id'].'">'.$option['name'].' (+'.money($currently).')</option>'."\n";
						}
							
						$string .= '</select>';
					}
					if (!empty($options['after_menu'])) $string .= $options['after_menu']."\n";

				}

				return $string;
				break;

			case "donation":
			case "amount":
			case "quantity":
				if ($this->outofstock) return false;
				if (!isset($options['value'])) $options['value'] = 1;
				if (!isset($options['input'])) $options['input'] = "text";
				if (!isset($options['labelpos'])) $options['labelpos'] = "before";
				if (!isset($options['label'])) $label ="";
				else $label = '<label for="quantity'.$this->id.'">'.$options['label'].'</label>';
				
				$result = "";
				if ($options['labelpos'] == "before") $result .= "$label ";
				
				if (!isset($this->_prices_loop)) reset($this->prices);
				$variation = current($this->prices);

				if (isset($options['input']) && $options['input'] == "menu") {
					if (!isset($options['options'])) 
						$values = "1-15,20,25,30,40,50,75,100";
					else $values = $options['options'];
					if ($this->inventory && $this->max['stock'] == 0) return "";	
				
					if (strpos($values,",") !== false) $values = explode(",",$values);
					else $values = array($values);
					$qtys = array();
					foreach ($values as $value) {
						if (strpos($value,"-") !== false) {
							$value = explode("-",$value);
							if ($value[0] >= $value[1]) $qtys[] = $value[0];
							else for ($i = $value[0]; $i < $value[1]+1; $i++) $qtys[] = $i;
						} else $qtys[] = $value;
					}
					$result .= '<select name="products['.$this->id.'][quantity]" id="quantity-'.$this->id.'">';
					foreach ($qtys as $qty) {
						$amount = $qty;
						$selected = (isset($this->quantity))?$this->quantity:1;
						if ($variation->type == "Donation" && $variation->donation['var'] == "on") {
							if ($variation->donation['min'] == "on" && $amount < $variation->price) continue;
							$amount = money($amount);
							$selected = $variation->price;
						} else {
							if ($this->inventory && $amount > $this->max['stock']) continue;	
						}
						$result .= '<option'.(($qty == $selected)?' selected="selected"':'').' value="'.$qty.'">'.$amount.'</option>';
					}
					$result .= '</select>';
					if ($options['labelpos'] == "after") $result .= " $label";
					return $result;
				}
				if (valid_input($options['input'])) {
					if (!isset($options['size'])) $options['size'] = 3;
					if ($variation->type == "Donation" && $variation->donation['var'] == "on") {
						if ($variation->donation['min']) $options['value'] = $variation->price;
						$options['class'] .= " currency";
					}
					$result = '<input type="'.$options['input'].'" name="products['.$this->id.'][quantity]" id="quantity-'.$this->id.'"'.inputattrs($options).' />';
				}
				if ($options['labelpos'] == "after") $result .= " $label";
				return $result;
				break;
			case "input":
				if (!isset($options['type']) || 
					($options['type'] != "menu" && $options['type'] != "textarea" && !valid_input($options['type']))) $options['type'] = "text";
				if (!isset($options['name'])) return "";
				if ($options['type'] == "menu") {
					$result = '<select name="products['.$this->id.'][data]['.$options['name'].']" id="data-'.$options['name'].'-'.$this->id.'">';
					if (isset($options['options'])) 
						$menuoptions = preg_split('/,(?=(?:[^\"]*\"[^\"]*\")*(?![^\"]*\"))/',$options['options']);
					if (is_array($menuoptions)) {
						foreach($menuoptions as $option) {
							$selected = "";
							$option = trim($option,'"');
							if (isset($options['default']) && $options['default'] == $option) 
								$selected = ' selected="selected"';
							$result .= '<option value="'.$option.'"'.$selected.'>'.$option.'</option>';
						}
					}
					$result .= '</select>';
				} elseif ($options['type'] == "textarea") {
					if (isset($options['cols'])) $cols = ' cols="'.$options['cols'].'"';
					if (isset($options['rows'])) $rows = ' rows="'.$options['rows'].'"';
					$result .= '<textarea  name="products['.$this->id.'][data]['.$options['name'].']" id="data-'.$options['name'].'-'.$this->id.'"'.$cols.$rows.'>'.$options['value'].'</textarea>';
				} else {
					$result = '<input type="'.$options['type'].'" name="products['.$this->id.'][data]['.$options['name'].']" id="data-'.$options['name'].'-'.$this->id.'"'.inputattrs($options).' />';
				}
				
				return $result;
				break;
			case "outofstock":
				if ($this->outofstock) {
					$label = isset($options['label'])?$options['label']:$Shopp->Settings->get('outofstock_text');
					$string = '<span class="outofstock">'.$label.'</span>';
					return $string;
				} else return false;
				break;
			case "buynow":
				if (!isset($options['value'])) $options['value'] = __("Buy Now","Shopp");
			case "addtocart":
			
				if (!isset($options['class'])) $options['class'] = "addtocart";
				else $options['class'] .= " addtocart";
				if (!isset($options['value'])) $options['value'] = __("Add to Cart","Shopp");
				$string = "";
				
				if ($this->outofstock) {
					$string .= '<span class="outofstock">'.$Shopp->Settings->get('outofstock_text').'</span>';
					return $string;
				}
				if (isset($options['redirect']) && !isset($options['ajax'])) 
					$string .= '<input type="hidden" name="redirect" value="'.$options['redirect'].'" />';
				
				$string .= '<input type="hidden" name="products['.$this->id.'][product]" value="'.$this->id.'" />';

				if (!empty($this->prices[0]) && $this->prices[0]->type != "N/A") 
					$string .= '<input type="hidden" name="products['.$this->id.'][price]" value="'.$this->prices[0]->id.'" />';

				if (!empty($Shopp->Category)) {
					if (SHOPP_PERMALINKS)
						$string .= '<input type="hidden" name="products['.$this->id.'][category]" value="'.$Shopp->Category->uri.'" />';
					else
						$string .= '<input type="hidden" name="products['.$this->id.'][category]" value="'.((!empty($Shopp->Category->id))?$Shopp->Category->id:$Shopp->Category->slug).'" />';
				}

				$string .= '<input type="hidden" name="cart" value="add" />';
				if (isset($options['ajax'])) {
					if ($options['ajax'] == "html") $options['class'] .= ' ajax-html';
					else $options['class'] .= " ajax";
					$string .= '<input type="hidden" name="ajax" value="true" />';
					$string .= '<input type="button" name="addtocart" '.inputattrs($options).' />';					
				} else {
					$string .= '<input type="submit" name="addtocart" '.inputattrs($options).' />';					
				}
				
				return $string;
		}
		
		
	}

} // END class Product

class Spec extends MetaObject {
	
	function __construct ($id=false) {
		$this->init(self::$table);
		$this->load($id);
		$this->context = 'product';
		$this->type = 'spec';
	}
	
	function updates ($data,$ignores=array()) {
		parent::updates($data,$ignores);
		if (preg_match('/^.*?(\d+[\.\,\d]*).*$/',$this->value))
			$this->numeral = preg_replace('/^.*?(\d+[\.\,\d]*).*$/','$1',$this->value);
	}

} // END class Spec

?>