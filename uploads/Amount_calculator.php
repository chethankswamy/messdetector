<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Tax library to calcualte the total amount after applying tax and discount on base amount 
 * @package    Core
 * @Copyright (c) 2011 Acclivity Group LLC
 * @created date 13/01/2012
 */
class Amount_calculator_core {
	/*
	 * function to calculate total amount
	 * Params:
	 * $base_amount - Double , base amount of the stream
	 * $discount_amount - Float, discount for the customer
	 * $is_discount_amount - int, 1- amount , 0- percentage
	 * $tax1 = float , Tax 1 percentage
	 * $tax2 - float , Tax 2 percentage
	 */
	private $payment_stream_model;
 	private $transaction_model;
 	private $stream_subscriber_model;
 	
 	function __construct() {
 		$this->payment_stream_model	= new Paymentstream_Model;
 		$this->transaction_model	= new Transaction_Model;
 		$this->stream_subscriber_model = new Stream_subscriber_Model;
 	}
	
	public static function calculate_total($transaction_total_amount, $base_amount,$discount_amount,$is_discount_amount,$tax1=0,$tax2=0) 
	{
		if($base_amount == 0 || $transaction_total_amount == 0) {
			return 0;
		}
		// round the base amount first
		$base_amount = Amount_calculator :: round_price ($base_amount);
		// if the discount amount is in percentage convert to amount
		if($is_discount_amount == 0) {
			$discount_amount = ($discount_amount/100) * $base_amount;
		} else {
			$discount			=	(($discount_amount * 100)/$transaction_total_amount); //covert to % first
			//$discount 			= 	Amount_calculator :: round_price($discount);  //Commented on 19/11/2012 - AED Sales need to be tested thoroughly
			$discount_amount 	= 	($discount/100) * $base_amount;
		}
		//$discount_amount = Amount_calculator :: round_price($discount_amount);
		// Apply the discount on base amount
		$sub_total = $base_amount - self::round_price($discount_amount);
		// Round sub total
		//$sub_total = Amount_calculator :: round_price($sub_total);
		
		// Apply the tax 1 on sub total
		$tax_1_amount = self::round_price(($tax1/100)*$sub_total);
		// round tax 1
		//$tax_1_amount = Amount_calculator :: round_price($tax_1_amount);
		// Apply the tax 2 on sub total
		$tax_2_amount = self::round_price(($tax2/100)*$sub_total);
		// round tax 2
		//$tax_2_amount = Amount_calculator :: round_price($tax_2_amount);
		// Round total tax
		$total_tax = $tax_1_amount + $tax_2_amount;//Amount_calculator :: round_price($tax_1_amount + $tax_2_amount);
		
		// Calculate total
         
        //remove round off and check in all intergration
		$total = $sub_total + self::round_price($total_tax);
		

        //$total = Amount_calculator :: round_price($total);
		return self::round_price($total);
	}
	
	public static function calculate_total_unformatted($base_amount,$discount_amount,$is_discount_amount,$tax1=0,$tax2=0) 
	{
		if($base_amount == 0) {
			return 0;
		}
		// round the base amount first
		$base_amount = Amount_calculator :: round_price($base_amount);
		// if the discount amount is in percentage convert to amount
		if($is_discount_amount == 0) {
			$discount_amount = ($discount_amount/100) * $base_amount;
		}
		// Apply the discount on base amount
		$sub_total = $base_amount - $discount_amount;
		// Round sub total
		$sub_total = Amount_calculator :: round_price($sub_total);
		
		// Apply the tax 1 on sub total
		$tax_1_amount = ($tax1/100)*$sub_total;
		// round tax 1
		$tax_1_amount = Amount_calculator :: round_price($tax_1_amount);
		// Apply the tax 2 on sub total
		$tax_2_amount = ($tax2/100)*$sub_total;
		// round tax 2
		$tax_2_amount = Amount_calculator :: round_price($tax_2_amount);
		// Round total tax
		$total_tax = Amount_calculator :: round_price($tax_1_amount + $tax_2_amount);
		
		// Calculate total
		$total = $sub_total + $total_tax;
		$total = Amount_calculator :: round_price($total);
		return $total;
	}
	
	/*
	 * Function to generate Rerun transaction Id
	 * params: $transaction_id
	 * returns: 8 digit transaction Id
	 */
	
	public function get_rerun_transaction_id($transaction_id)
	{
	
		if($transaction_id != '' && $transaction_id != 0 ) {  
		 	$temp_trans_id =  str_pad($transaction_id,7,"0", STR_PAD_LEFT); 
		 	$new_trans_id  =  "R".$temp_trans_id;
		 	return $new_trans_id;
		} else {
			return 0;
		}
	}

 	// Function to round the price value
 	public function round_price($price) {
        /*
         * Commented the existing one as ther was issues while calculating the tax
 		$rounded_price = sprintf("%0.2f", $price);
		return $rounded_price; 
         */
        return round($price, 2);
 	}
 	
 	//Function to do total amount by applying tax to each item price
 	public function get_stream_total_price($stream_subscriber_id, $customer_id, $base_amount, $discount_amount, $is_discount_amount, $tax1=0, $tax2=0, $cc_percentage=0) {
 		$payment_stream_model	=	new Paymentstream_Model;
 		$stream_total			=	0;
 		$item_details			=	$payment_stream_model->get_subscribed_items($stream_subscriber_id, $customer_id);
 		if(empty($item_details)) {
 			return $base_amount;
 		} else {
 			$transaction_total_amount	=	$base_amount;
 			foreach($item_details as $stream) {
 				$item_price		=	$stream['item_price'];
 				$stream_total	+=	$this->calculate_total($transaction_total_amount, $item_price, $discount_amount, $is_discount_amount, $tax1, $tax2);	
 			}
 		}
                if($cc_percentage){
                    $cc_processing_fee      =   ($stream_total*$cc_percentage)/100;
		      $cc_processing_fee      =   Amount_calculator :: round_price($cc_processing_fee);
                    $stream_total           =   $stream_total + $cc_processing_fee;
                }
 		$stream_total = number_format($stream_total,2,'.','');
 		return $stream_total;
 	}
 	
 	//function to get discount on individual item
 	public function get_item_discount($base_amount,$item_amount,$discount_amount,$is_discount_amount)
 	{
 		if($base_amount == 0 || $item_amount == 0) {
			return 0;
		}
 		$base_amount = Amount_calculator :: round_price($base_amount);
		// if the discount amount is in percentage convert to amount
		if($is_discount_amount == 0) {
			$discount_amount = ($discount_amount/100) * $item_amount;
		} else {
			$discount			=	(($discount_amount * 100)/$base_amount); //covert to % first
			//$discount 			= 	Amount_calculator :: round_price($discount);
			$discount_amount 	= 	($discount/100) * $item_amount;
		}
		//$discount_amount = Amount_calculator :: round_price($discount_amount);
		return $discount_amount;
 	}
 	
 	public function get_item_tax($transaction_total_amount, $base_amount,$discount_amount,$is_discount_amount,$tax=0) 
 	{
 		if($transaction_total_amount == 0 || $base_amount == 0) {
			return 0;
		}
 		// round the base amount first
		$base_amount = Amount_calculator :: round_price($base_amount);
		// if the discount amount is in percentage convert to amount
		if($is_discount_amount == 0) {
			$discount_amount = ($discount_amount/100) * $base_amount;
		} else {
			$discount			=	(($discount_amount * 100)/$transaction_total_amount); //covert to % first
			//$discount 			= 	Amount_calculator :: round_price($discount);
			$discount_amount 	= 	($discount/100) * $base_amount;
		}
		//$discount_amount = Amount_calculator :: round_price($discount_amount);
		// Apply the discount on base amount
		$sub_total = $base_amount - $discount_amount;
		// Round sub total
		//$sub_total = Amount_calculator :: round_price($sub_total);
		
		// Apply the tax 1 on sub total
		$tax_amount = ($tax/100)*$sub_total;
		// round tax 1
		//$tax_amount = Amount_calculator :: round_price($tax_amount);
		return $tax_amount;
 	}
 	
 	// function to store items used in a trxn
 	public function insert_transaction_items($subscriber_id,$transaction_id) {
 		$subscribed_items	    =	$this->payment_stream_model->get_subscribed_items($subscriber_id);
 		foreach($subscribed_items as $item) {
 			$data = array('transaction_id'=>$transaction_id,
 						  'item_id'=>$item['item_id'],
 						  'item_price'=>$item['item_price']);
 			$this->transaction_model->insert_transaction_items($data);
 		}
 	}
 	
 	// function to insert base, tax and discounts used in the transaction
 	public function insert_transaction_amounts($subscriber_id,$transaction_id) {
 		$subscription = $this->stream_subscriber_model->read($subscriber_id);
 		if(!empty($subscription)) {
 			$subscription = $subscription[0];
 			$base_amount = $subscription->base_amount;
 			$discount_applied = $subscription->discount_amount;
 			$is_amount = $subscription->is_amount;
 			$tax1_id = $subscription->tax_1_id;
 			$tax2_id = $subscription->tax_2_id;
 			$tax1_percentage = $subscription->sales_tax_1;
 			$tax2_percentage = $subscription->sales_tax_2;
 			
 			$discount_amount = 0;
 			$tax1_amount = 0;
 			$tax2_amount = 0;
 			
 			$subscribed_items =	$this->payment_stream_model->get_subscribed_items($subscriber_id);
 			foreach($subscribed_items as $item) {
 				$tax1_amount += $this->get_item_tax($base_amount,$item['item_price'],$discount_applied,$is_amount,$tax1_percentage);
 				$tax2_amount += $this->get_item_tax($base_amount,$item['item_price'],$discount_applied,$is_amount,$tax2_percentage);
 				$discount_amount += $this->get_item_discount($base_amount,$item['item_price'],$discount_applied,$is_amount);
 			}
 			foreach(array('transaction_id','base_amount','tax1_id','tax1_amount','tax2_id','tax2_amount','tax1_percentage','tax2_percentage','discount_amount') as $key) {
 				$$key = is_null($$key) ? 0 : $$key;
 			}
 			$data = array('transaction_id'=>$transaction_id,
 						  'base_amount'=>$base_amount,
 						  'tax1_id'=>$tax1_id,
 						  'tax1_amount'=>number_format($tax1_amount,2,'.',''),
 						  'tax2_id'=>$tax2_id,
 						  'tax2_amount'=>number_format($tax2_amount,2,'.',''),
 						  'tax1_percentage'=>$tax1_percentage,
 						  'tax2_percentage'=>$tax2_percentage,
 						  'discount_amount'=>$discount_amount);
 			$this->transaction_model->insert_transaction_amounts($data);
 		} else {
 			$data = array('transaction_id'=>$transaction_id); // other columns will be defaulted to 0
 			$this->transaction_model->insert_transaction_amounts($data);
 		}
 	}
 	
 	// function to get items used in transactions <--> global accessible for transcation model method get_transaction_items
 	public function get_transaction_items($transaction_id) {
 		return $this->transaction_model->get_transaction_items($transaction_id);
 	}
 	
 	// function to get amounts for transactions <--> global accessible for transcation model method get_transaction_amounts
	public function get_transaction_amounts($transaction_id) {
 		return $this->transaction_model->get_transaction_amounts($transaction_id);
 	}
 	
 	// get final transaction amount 
 	public function get_transaction_amount($base_amount,$final_tax,$final_discount) {
 		return number_format(($base_amount + $final_tax - $final_discount),2);
 	}
 	
 	//function to get subscribed items <-->global accessible for payment_stream_model model method get_subscribed_items
 	public function get_subscribed_items($subscriber_id) {
 		return $this->payment_stream_model->get_subscribed_items($subscriber_id);
 	}
    
    //function to format a tax
    public function format_tax($tax_amount){
        $decimal_points = strlen(substr(strrchr($tax_amount, '.'),1));
        $formatted_points = $decimal_points <= 2 ? 2 : 4; // if there are <= 2 digits after decimal point, then format it to 2, else 4 decimal points
        return number_format($tax_amount,$formatted_points);
    }
    
    /**
     * Function to unformat a number
     * @param string $number
     * @return string 
     */
    public function number_unformat($number) {
        return filter_var($number,FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION);
    }

}
?>