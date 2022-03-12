<?php

/**
 * @file
 *  RusaRbaForm.php
 *
 * @Created 
 *  2018-01-09 - Paul Lieberman
 *
 * RBA Self Service Form. 
 * User first selects the Region and provides the RBA ID and the Club ID for that region.
 * The next screen shows the calendared Events for that Region with a drop down select box
 * for available routes.
 * Routes are filtered by the Region, their active flag, and the Event distance.
 * An Event may already have a Route assigned in which case it is the default option.
 * RUSA Events allow the RBA to set either the standard distance or the Route distance.
 *
 * ----------------------------------------------------------------------------------------
 * 
 */

namespace Drupal\rusa_rba\Form;

use Drupal\rusa_api\RusaRoutes;
use Drupal\rusa_api\RusaClubs;
use Drupal\rusa_api\RusaOfficials;
use Drupal\rusa_api\RusaRegions;
use Drupal\rusa_api\RusaEvents;
use Drupal\rusa_api\Client\RusaClient;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * RusaRbaForm
 *
 * This is the Drupal Form class.
 * All of the form handling is within this class.
 *
 */
class RusaRbaForm extends FormBase {

  // Instance variables

  // We have other classes to hold the data from the backend tables
  protected $regobj;    // Region object
  protected $eobj;      // Event object
  protected $robj;      // Route object
  protected $results;   // Array of Events that have changed

  // Who to notify
  protected $bc_mail = "rusa";
  protected $rba;     // Includes mid, name, and e-mail

  /**
   * @getFormID
   *
   * Required
   *
   */
  public function getFormId() {
    return 'rusa_rba_form';
  }

  /**
   * @Constructor
   *
   * Initialize our region data before we do anything else
   */
  public function __construct(){

    // Get the active regions from the database
    $this->regobj = new RusaRegions(['key' => 'status', 'val' => 1]);
  } 


  /**
   * @buildForm
   *
   * Required
   *
   * This is the Drupal form builder. It is the heart of the whole thing.
   *
   * We are implimenting a  multistep form. This is probably a little different than most Drupal forms. 
   * The submit handler just sets the rebuild flag and returns control to the build function.
   * We keep tracdk of the form state and branch accordingly.
   * Most of the code to actually define the form fields is in subroutines.
   * This function mostly adds the structure for multistep form handling.
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state) {   
    // If form has been submitted see what state we are in
    if ($form_state->isSubmitted()) {
   
      switch ($form_state->getValue('stage')) {

        // If a Region has been selected 
        case "step1" : 
          $regid = $form_state->getValue('regions');
          // We have the Region ID so save it
          $this->regobj->setSelectedRegion($regid);
        
          // And initialize our  Events and Routes objects
          $this->eobj    = new RusaEvents(['regid' => $regid]); 
          $this->robj    = new RusaRoutes(['key' => 'regid', 'val'  => $regid]);

          // Now display the Events with route selection
          $this->display_events($form, 1);
          break;
     
        case "step_back" :
          $this->display_events($form, 1);
          break;

        case "step2" :
          // Now display the Events read only
          $this->display_events($form, 0);
          break;
       
        case "step3" :
          // Form submission is complete
          $form['instruct'] = [
            '#type'     => 'item',
            '#markup'   => $this->t("Your changes have been saved. You can return here at any time to make further changes."), 
          ];
      }
    }
    // No region has been selected yet so show Region dropdown
    // and RBA ID and Club fields
    else {
      $this->display_regions($form);
    }
    // Attach the Javascript and CSS, defined in rusa_rba.libraries.yml.
    $form['#attached']['library'][] = 'rusa_api/chosen';
    $form['#attached']['library'][] = 'rusa_api/rusa_script';
    $form['#attached']['library'][] = 'rusa_api/rusa_style';

    return $form;
  }

 /**
   * @validateForm
   *
   * Required
   *
   * We do a lot of work here.
   *   - Trap button clicks
   *   - Set the finish state if user wants to go back or  cancel
   *   - Determine what state we are in
   *   - Do some validation
   *   - Set the state so that the form returns to the right place
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $action     = $form_state->getTriggeringElement();
    $event_data = $form_state->getValue('event_data');

   // First see if cancel was clicked
    if ($action['#type'] == "button") {
      if ($action['#value'] == "Go back and make further changes" ){
        // Go back to editing routes
        // We have to reset some values to pass them back to the form
        $values = [
          'regions'    => $this->regobj->getSelectedRegionId(),
          'stage'      => "step_back",
          'event_data' => $event_data,
        ];
        $form_state->setValues($values);
        // Set the rebuild flag and go back
        $form_state->setRebuild();
        $form_state->setSubmitted();
      }
      else { 
        // Really cancel
        $form_state->setValues(['stage' => 'fini']);
        $form_state->setSubmitted();
      }
    }  
    // Else submit was clicked and we can use our "stage" value to see where we are
    else {
      switch ($form_state->getValue('stage')) {

        case "step1" :
          // Verify user is RBA for regions, and has entered the correct club
          $mid = $form_state->getValue('mid');
          $regid   = $form_state->getValue('regions');
          $acpcode = $form_state->getValue('acpcode');

          // Get the region by id
          $region = $this->regobj->getRegion($regid);

          // Get the Club and RBA from the region
          $clubid  = $region->orgclub; 
          $rbaid   = $region->rbaid;

          // Verify RBA and Club belong to this Region
          if ($mid != $rbaid) {
            // Not the right RBA 
            $form_state->setErrorByName('mid', 
            $this->t("Member with id " . $mid . " is not the RBA for the selected region. Please try again."));  
          }

          if ($acpcode != $clubid) {
            // Not the right club
            $form_state->setErrorByName('acpcode', 
            $this->t("Club with acpcode " . $acpcode . " is not the organizing club for the selected region. Please try again."));  
          }
          break;

        case "step2" :
          // Validation for the main form
          // Check to see that the event distance is not more than the route distance.
          foreach ($event_data as $row => $event) {
            if ($event['route_name']) {
              $route_dist = $this->robj->getRouteDistance($event['route_name']);
              $event_dist = $this->eobj->getEventDistance($event['eid']);
              $dist_opt   = $event['dist_option'];
              if ($event_dist > $route_dist && $dist_opt == 0) {
                $form_state->setError($form['event_data'][$row],
                    $this->t("Calendared distance cannot be greater than Route distance. " .
                        "Please select 'Use route distance' or select a different route"));
              }
              // Any other per event validation would go here.
            }
          }
          // Check to see if there are actually any changes.
          $this->save_results($event_data);
          if (empty($this->results)) {
            // No changes so reset some values and go back
            $values = [
              'regions'    => $this->regobj->getSelectedRegionId(),
              'stage'      => "step_back",
              'event_data' => $event_data,
            ];
            $form_state->setValues($values);
            $form_state->setError($form, $this->t("You have not made any changes. Use cancel if you just want to abandon."));
          } 
          break;

        case  "step3" :
          // Make sure there are actual changes
          if (empty($this->results)) {
            // Actually this should never happen now that we are catching this above
            $form_state->setError($form, $this->t("You have not made any changes. Use cancel if you just want to abandon."));
          }
          else {
            // Post the results to the backend
            $err = $this->post_results();
            if (! $err->success) {
              $message = $err->error->message;
              $form_state->setError($form,   
                $this->t("The server returned an error when posting your changes. The message is: <br />" . $message));
            }
            else {
              $this->messenger()->addStatus($this->t("Your updates have been saved."));
              $form_state->setValues(['stage' => 'fini']);
            }
          }
          break;

      } // End switch
    } // End if cancel
  } // End function verify


  /**
   * @submitForm
   *
   * Required
   *
   * We don't do much here.
   *    - Redirect on final submission
   *    - Else set the rebuild flag and return
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('stage') == 'fini') {
      $form_state->setRedirect('rusa_rba.resources');
    }
    else { 
      // Set the rebuild flag and go on to the next state
      $form_state->setRebuild();
    }
  }

  //  Private functions
  // ------------------------------------------------------------

  /**
   * Display regions, RBA id, and Club id form inputs
   *
   */
  private function display_regions(&$form) {
    $options = [];

    $regions = $this->regobj->getRegions();
    foreach ($regions as $regid => $region) {
      $options[$regid]  = $region->state . ' ' . $region->city;  
    }

    // Sort the regions by state
    asort($options);

    // Some instructions at the top
    $form['instruct'] = [
      '#type'    => 'item',
      '#markup'  => $this->t("<p>RBAs can use this page to assign or change routes for scheduled events for which results have not yet been submitted. <br />" .
                             "Select the region in which the events will be held, and identify the event organizer and club.</p>"),
    ];

    // Build a select list of regions
    $form['regions'] = [
      '#type'    => 'select',
      '#title'   => $this->t('Select region'),
      '#options' => $options,
    ];

    // Get the users member ID
    $form['mid'] = [
      '#type'     => 'textfield',
      '#title'    => $this->t("RBA's RUSA number"),
    ];

    // Get club ACP code
    $form['acpcode'] = [
      '#type'    => 'textfield',
      '#title'   => $this->t("Organizing club's ACP number"),
    ];

    // Form state
    $form['stage'] = [
      '#type'   => 'hidden',
      '#value'  => 'step1',
    ];

    // Actions wrapper
    $form['actions'] = [
      '#type' => 'actions'
    ];

    // Default submit button 
    $form['actions']['submit'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Find events'),
    ];
  }


 /**
   * get_region_data
   *
   * Format the data to display as a table above the table of events.
   * We want to display the region name, club, and RBA name.
   * So we have to pull in data from the Clubs and Officials tables.
   */
  private function get_region_data() {
    $region      = $this->regobj->getSelectedRegion();
    $rbaid       = $region->rbaid;
    $region_name = $region->state . " " . $region->city;

    // Get the club name
    $club = new RusaClubs(['key' => 'acpcode', 'val' => $region->orgclub]);
    $club_name = $club->getClubName();

    // Get the RBA
    $this->rba = $this->get_official($rbaid);

    // Build the form table element
    $region_data = [
     '#type'   => 'table',
     '#sticky' => TRUE,
     '#header'  => [
        $this->t('Region'),
        $this->t('Club'),
        $this->t('RBA'),
      ],
      '#rows'  => [
        [$region_name, $club_name, $this->rba->fname . " " . $this->rba->sname],
      ],
    ];
    return $region_data;
  }


/* ------------------------------ Route selection -------------------------------------------- */
  /**
   * display_events
   *
   * This is the main table of events and routes.
   * 
   * @param $edit  Boolean
   *
   * We passased in an $edit flag to tell us to display it
   * with form fileds, or read only for confirmation.
   *
   */
  private function display_events(&$form, $edit) {

    // Show instuctions
    if ($edit) {
      $form['instruct'] = [
        '#type'   => 'item',
        '#markup' => $this->t(
"<p>You can assign or change the route for events EXCEPT for events for which results have been submitted (these events are grayed out and unchangeable).<br />" .
"To remove a route assignment and not choose a different one, select '--Select Route--'. <br />" .
"You can set the event distance for RUSA events to use the existing calendared distance, or the route distance for the selected route.  If the selected route distance is less than the current calendared distance, you must change the distance selection to 'Use Route Distance'. </p>" .
"When you submit this form you will see another screen where you can confirm your updates before the Ride Calendar is updated.<br />" .
"To exit this page with no changes, select 'Cancel'.</p>"
        ),
      ];
    }
    else {
      $form['instruct'] = [
        '#type'   => 'item',
        '#markup' => $this->t(
          "<p>Please review your changes here. Events that have been changed are shown in <b>bold face</b>.<br />" .
          "When you submit this form your changes will be committed to the RUSA Calendar.</p>"
        ),
      ];
    }

     // Display Region and RBA data as a table above the events table
    $form['region_data'] = $this->get_region_data();

    // Events table header
    $form['event_data'] = [
      '#type'   => 'table',
      '#sticky' => FALSE,
      '#header'  => [
        $this->t('Type'),
        $this->t('Calendared<br /> Distance'),
        $this->t('Date'),
        $this->t('Route'),
        $this->t('Distance Options'),
      ],
    ];

    // Get the Event data
    $edata = $this->get_event_data();
    $row = 0;

    // We have all of the event data and current route assignments.
    // Now we have to loop through it and figure out how to display it.
    // It could be a form field, or plain text.
    // Lots of decisions to make here.
    foreach ($edata as $event) {       
      // Skip team events
      if ($event['type'] == "ACPF" || $event['type'] == "RUSAF") {
        continue;
      }

      $edit_event = TRUE; // Temporarily assume this event will be editable

      // This starts the actual form element, 
      // which is an indexed array, with each array elemnt holding the form values.
      // See the Drupal 8 form API for a reference to the field types.
      $form['event_data'][$row]['type']  = ['#type' => 'item',   '#markup' => $event['type']];
      $form['event_data'][$row]['dist']  = ['#type' => 'item',   '#markup' => $event['dist'] . "km"];
      $form['event_data'][$row]['date']  = ['#type' => 'item',   '#markup' => $event['date']];

      // If event results have been submitted
      // then set a flag so this line will be read only,
      // and add a CSS class so we can style it.
      if ($event['resultsSubmitted']) {
        $form['event_data'][$row]['#attributes']['class'][] = 'rusa-event-past';
        $edit_event = FALSE;
      }

      // If there are no approved routes available
      if (empty($event['route_name'])){
        $edit_event = FALSE;
      }


      // Editable Route selection
      // The $edit boolean was passes into this function,
      // and the $edit_event boolean was set above.
      if ($edit && $edit_event) {

        // This is the top line in the options dropdown. 
        // If a route is already assigned we'll set it to Unassign.
        $empty_option = $event['rtid'] ? "-- Unassign Route --" : "-- Select Route --";
        

        // Route selection drop down.
        // The actual select box of routes for each event.
        $form['event_data'][$row]['route_name'] = [
          '#type'          => 'select',
          '#options'       => $event['route_name'],
          '#default_value' => $event['rtid'],
          '#empty_option'  => $this->t($empty_option),
          '#empty_value'   => 0,
          '#attributes'    => ['class' => ['rusa-route-select']], //add a CSS class for styling.
        ]; 

        // Radio buttons for the distance options only on RUSA events
        if ($event['type'] === "RUSAB" || $event['type'] === "RUSAP" || $event['type'] === "RM") {
          $form['event_data'][$row]['dist_option'] = [
            '#type'          => 'radios',
            '#options'       => [0 => 'Keep Calendared Distance', 1 => 'Use Route distance'],
            '#default_value' => 0,
          ];
        }
        else {
          // No radio button, display the event distance instead.
          $form['event_data'][$row]['dist_option']  = ['#type' => 'item', '#markup' => $event['dist'] . "km"];
        }
      }
      else {
        // Read only

        // If event changed add a class so we can highlight it
        $class = $this->results[$event['id']] ? 'rusa-event-changed' : 'rusa-event';
        $form['event_data'][$row]['#attributes']['class'][] = $class;

        // If there are no approved routes available
        if (empty($event['route_name'])){
          $route_line = "-- No Routes Available --";
        }
        else {
          // Read only route name or TBD
          $route_line = $event['rtid'] ?  $this->robj->getRouteLine($event['rtid']) : "-- TBD --";
        }

        $form['event_data'][$row]['route_name'] = [
          '#type'           => 'item',
          '#markup'         => $route_line,
        ]; 
        $form['event_data'][$row]['dist_option'] = ['#type' => 'item', '#markup' => $event['dist'] . "km"];
      }
      $form['event_data'][$row]['eid'] = ['#type' => 'hidden', '#value' => $event['id']];
      $row++;

    } // End foreach


    // Check and keep track of the form state, or stage, or step. 
    // And provide the appropriate buttons.
    if ($edit) {
      $form['stage'] = [
        '#type'   => 'hidden',
        '#value'  => 'step2',
      ];

      // Actions wrapper
      $form['actions'] = [
        '#type' => 'actions'
      ];

      // Cancel button
      $form['actions']['cancel'] = [
        '#type'  => 'button',
        '#value' => $this->t("Cancel"),
        '#attributes' => ['onclick' => 'if(!confirm("Do you really want to cancel?")){return false;}'],
      ];
    
      // Save button 
      $form['actions']['submit'] = [
        '#type'  => 'submit',
        '#value' => $this->t("Review route assignments"),
      ];
    }
    else {
      // Form state
      $form['stage'] = [
        '#type'   => 'hidden',
        '#value'  => 'step3',
      ];

      // Actions wrapper
      $form['actions'] = [
        '#type' => 'actions'
      ];

      // Cancel button
      $form['actions']['cancel'] = [
        '#type'  => 'button',
        '#value' => $this->t("Cancel"),
        '#attributes' => ['onclick' => 'if(!confirm("Abandon all changes and leave this form?")){return false;}'],
      ];

      // Go back button
      $form['actions']['goback'] = [
        '#type'  => 'button',
        '#value' => "Go back and make further changes",
      ];
    
      // Save button 
      $form['actions']['submit'] = [
        '#type'  => 'submit',
        '#value' => $this->t("Update calendar"),
      ];
    }
  }

   /**
   * Get Event Data
   *
   * This is all the data that goes into the form table.
   * It is contained in a single array which is retured to the function above.
   */
  private function get_event_data() {
    // Get all events and routes for this region

    $events = $this->eobj->getEvents();            
    $routes = $this->robj->getRoutes();    
 
    foreach ($events as $eid => $event) {
      $this_event = [];
      $this_event['id']   = $eid;
      $this_event['type'] = $event->type;
      $this_event['dist'] = $event->dist;
      $this_event['date'] = $event->date;
      $this_event['resultsSubmitted'] = $event->resultsSubmitted;
      if (!is_null($event->rtid) && is_numeric($event->rtid)) {
        $this_event['rtid']       = $event->rtid;
        $this_event['route_dist'] = $routes[$event->rtid]->dist;
      }
      else {
        $this_event['rtid'] = 0;
      }

      // Calculate minimum distance if distance > standard distance
      $std_dist = floor($event->dist / 100) * 100;
      $min_dist = $event->dist * .95;
      $dist     = $min_dist > $std_dist ? $min_dist : $std_dist;
      $this_event['route_name'] =  $this->get_routes_for_event($dist);

      // Add the event to the array
      $edata[] = $this_event;
     
    } // End loop for each event

    // Sort the array of events by Date
    usort($edata,
      function($a, $b) {
        $da = strtotime($a['date']);
        $db = strtotime($b['date']);
        return $da > $db;
      }
    );
    return $edata;
  }

  /**
  * get_routes_for_event
  *
  * Filters routes by distance
  *
  * Returns the options array used in the form select/
  */
  public function get_routes_for_event($dist) {

    // Filter the routes for the distance.
    // The Route object already filters by active.
    $routes = $this->robj->getRoutesByDistance($dist);
    $options = [];
    foreach ($routes as $rtid => $route) {
      // Our Route object has a function to format the select text
      // which is a combination of several fields.
      // The key is the route ID.
      $options[$rtid] = $this->robj->getRouteLine($rtid);
    }
    return $options;
  }
 
  // ------------------------------------------------------------------------

  /** 
   * Save results
   *
   * Save the results locally within our class data.
   * 
   * Loop through the submitted form and determine what has changed.
   * All we're doing is saving the IDs of Events that have change to
   * the $this->results array.
   */
  private function save_results($event_data) {

    foreach ($event_data as $event) {
      // Is there an actual route assignment? 
      if (is_numeric($event['route_name'])) {
       
        // Localize some variables for the sake of readability
        $rtid       = $event['route_name'];
        $eid        = $event['eid'];
        $orig_event = $this->eobj->getEvent($eid);
        $orig_rtid  = $orig_event->rtid == "TBD" ? 0 : $orig_event->rtid;

        // Has the route changed ?
        if ($rtid <> $orig_rtid) {
          if ($rtid > 0) {
            // Route assignment 
            $this->eobj->setRoute($eid, $rtid);
            $this->results[$eid] = 1;
          }       
          else {
            // Route unassignment
            $this->eobj->setRoute($eid, "TBD");
            $this->results[$eid] = 1;
          }
        }

        // Distance option has changed
        if ($event['dist_option'] == 1) {
          $this->eobj->setDistance($eid, $this->robj->getRouteDistance($rtid));
          $this->results[$eid] = 1;
        }
      }
    }
  }


  /**
   * post_results
   *
   * Post the results off to the backend.
   *
   * We don't actually interact with the database here.
   * Rather we are using our client class which passes the data to an API.
   * We expect the API to change from a simple CGI script to a REST interface
   * in the future, but the way this is written we won't have to make any changes here.
   *
   */
  private function post_results() {
    $results = [];
    // $this->results is just an array of event ids that have changed.
    // now we have to get the actual data.
    foreach ($this->results as $eid => $one) {
      $event =  $this->eobj->getEvent($eid); // The Event object will have the changes.
      $results[$eid] = $event;
      $changes[$eid] = [
        'date'    => $event->date,
        'route'   => $this->robj->getRouteLine($event->rtid), 
        'dist'    => $event->dist,
      ];
    }
    // Initialize our client to put the results.
    $client = new RusaClient();
    $err = $client->put($results);
    if ( $err->success) {
      // Send e-mail notifications
      $region      = $this->regobj->getSelectedRegion();
      
      $params['region_name'] = $region->state . ": " . $region->city;
      $params['rba_id']      = $region->rbaid;
      $params['rba_name']    = $this->rba->fname . " " . $this->rba->sname;
      $params['events']      = $changes;

      $to   = $this->rba->email . ", " . $this->bc_mail;
      $mail = \Drupal::service('plugin.manager.mail');

      $result = $mail->mail('rusa_rba', 'notify', $to, 'en', $params, $reply = NULL, $send = TRUE);

      $this->messenger()->addStatus($this->t("Notification e-mail has been sent."));
    }
    return $err; // We're not trapping any errors here, just passing them back.
  }

// These next functions pull data from the API
// They should probably be moved into their own classes
// as we did with Regions, Routes, and Events, 
// but they are pretty small.

 
  private function get_official($mid) {
    $query = [
      'key'  => 'mid',
      'val'  => $mid,
    ];
    $ofobj = new RusaOfficials($query);
    return  $ofobj->getOfficial($mid);
    
  }
  
} // End of class  
