<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">

        <!-- viewport meta to reset iPhone inital scale -->
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>PHP Mess Detector check tool</title>
        <link rel="shortcut icon" href="assets/images/favicon.png" />
        
        <!-- css3-mediaqueries.js for IE8 or older -->
        <!--[if lt IE 9]>
                <script src="http://css3-mediaqueries-js.googlecode.com/svn/trunk/css3-mediaqueries.js"></script>
        <![endif]-->
        
        <link href="assets/css/reset.css" type="text/css" rel="stylesheet" />
        <link href="assets/css/main.css" type="text/css" rel="stylesheet" />
    </head>
    <body>
        <div id="pagewrap">
            <div id="header">
                <h1><span class="sb-logo"></span>PHP Mess Detector tool</h1>
                <p><a href="http://phpmd.org/" target="_blank">PHP Mess Detector</a> is a great tool to help you produce high quality code by providing some of the feedback you else would only get by a human review. What PHPMD does is: It takes a given PHP source code base and look for several potential problems within that source. These problems can be things like: Possible bugs, Suboptimal code, Overcomplicated expressions, Unused parameters, methods, properties</p>
            </div>

            <div id="content">
                <div class="form-blk">
                    <form id="checkform" method="post" enctype="multipart/form-data" action="checkcode.php">
                        <ol class="circle-list">
                            <li>
                                <h2>Choose file:</h2>
                                <div class="file-btn">
                                    Browse<input type="file" name="file" id="file">
                                </div>
                            </li>
                            <li>
                                <h2>Check:</h2>
                                <button type="submit" class="file-btn submit">Submit</button>
                            </li>
                        </ol>
                    </form>
                </div>    
                <div id="output-blk">
                    <h5></h5>
                    <div class="output"></div>
                </div>
                <div class="loading">
                    <div class="bar">
                        <i class="sphere"></i>
                    </div>              
                </div>
            </div>

            <div id="footer">
                <p class="left">Check out <a href="http://<?php echo $_SERVER['HTTP_HOST'] ?>/codesniffer" target="_blank">PHP Code Sniffer Tool</a></p>
                <p class="right">No Copyrights &copy;  <span class="smile">;)</span></p>
            </div>

            <script type="text/javascript" src="assets/js/jquery-1.10.2.min.js"></script>
            <script type="text/javascript" src="assets/js/jquery.form.min.js"></script>
            <script type="text/javascript" src="assets/js/main.js"></script>
        </div>
    </body>
</html>
