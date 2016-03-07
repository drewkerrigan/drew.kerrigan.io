<?php
if(!$_POST) exit;
 
$values = array ('name','email','message');
$required = array('name','email','message');
 
$your_email = "dr.kerrigan@gmail.com";
$email_subject = "Email from online CV";
$email_content = "new message:\n";
 
for( $i = 0 ; $i < count( $values ) ; ++$i ) {
	for( $c = 0 ; $c < count( $required ) ; ++$c ) {
		if( $values[$i]==$required[$c] ) {
			echo $required[$x];
			if( empty($_POST[$values[$i]]) ) { echo '<span class="form_error">Please fill in all the fields</span>'; exit; }
		}
	}
	$email_content .= $values[$i].': '.$_POST[$values[$i]]."\n";
}
 
if(mail($your_email,$email_subject,$email_content)) {
	echo '<span class="form_sent">Message sent!</span>'; 
} else {
	echo '<span class="form_error">ERROR!</span>';
}
?>