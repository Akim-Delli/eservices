<?php

/**
 * Users Controller
 *
 * @property Dashboard $User
 */
class DashboardController extends AppController {
    var $uses = array('Cost', 'Project', 'User');

    public $helpers = array('Time');

    /**
     * index method
     *
     * @return void
     */
    public function index() {

        //Disable the layout
        $this->layout = false;

        $projectsListId = $this->Project->find('list', array('fields'     => array('id', 'project_name')));
        $usersRatePerHour = $this->User->find('list', array('fields' => array('firstname', 'costrate')));

        $arrDueDate = array();
        foreach ( $projectsListId as $projectId => $projectName) {
             $rawData = $this->Cost->find('all', array('conditions' => array('Project.id' => $projectId),
                                                              'fields'     => array( 'date', 'billinghours', 'fixedcost', 'User.firstname', 'Project.id','Project.project_name', 'Project.due_date'),
                                                              'order' => 'date ASC',
                                                              )
                                                );
             
             $arrBillingHoursUsers = array();
             $arrTotalBillingHours = array();
             
             
            foreach( $rawData as $arrData) {
                $arrDueDate[$projectId]= $arrData['Project']['due_date'];
                
                if ( isset( $usersRatePerHour[$arrData['User']['firstname']])) {
                    // array of array of timestamp in milliseconds and billing hours time the cost per rate for each user
                    $arrBillingHoursUsers[$arrData['User']['firstname']][] =  array( strtotime($arrData['Cost']['date']) * 1000 , 
                                                                                     $arrData['Cost']['billinghours'] * $usersRatePerHour[$arrData['User']['firstname']] );   
                    $arrTotalBillingHours[] =  array( strtotime($arrData['Cost']['date']) * 1000 , 
                                                                                     $arrData['Cost']['billinghours'] * $usersRatePerHour[$arrData['User']['firstname']] );   
                
                } else {
                    throw new Exception( $arrData['User']['firstname'] . " has no cost rate per hour", 1); 
                }           
            }

            $finalHcliteralSeries[$projectId] = $this->_formatBudgetSerieToHighcharts( $projectId) . $this->_formatSerieToHighchartsTotalCost($arrTotalBillingHours, $projectId) . $this->_formatSerieToHighcharts( $arrBillingHoursUsers);           
            $totalCosts[$projectId] = $this->_calculateTotalCost( $arrTotalBillingHours);
        }
            $this->set('finalHcliteralSeries', $finalHcliteralSeries );
            $this->set('arrDueDate', $arrDueDate);
            //debug( $arrDueDate);

            $users = $this->Cost->User->find('list');
            $usersfirstname = $this->Cost->User->find('list', array('fields'  => 'User.firstname'));

            $projects = $this->Cost->Project->find('all');
            $projectslist = $this->Cost->Project->find('list', array('fields'  => 'Project.project_name'));
            $this->set(compact('users', 'usersfirstname', 'projects', 'projectslist', 'totalCosts'));


            //  cost form Post request
            if ($this->request->is('post')) {
                $this->Cost->create();
                if ($this->Cost->save($this->request->data)) {

                    echo '<strong>' . $this->request->data['Cost']['billinghours'] .
                         '</strong> hours were added to the <strong>' . 
                          $projectslist[$this->request->data['Cost']['project_id']] .
                          '</strong> project for <strong>' .  $usersfirstname[$this->request->data['Cost']['user_id']] . '</strong>';
                    $this->render(false);
                    //$this->Session->setFlash(__('Cost saved.', 'default', array('class' => 'alert alert-info')));
                } else {
                    echo "Couldn't saved the cost!";
                    $this->render(false);
                }
            }
    } 
    /**
     * Format an array of array into a string of x,y pairs
     * to match the data series format in HighCharts:
     * { name : 'John' , data:  [ [x1,y1],[x2,y2],....] }
     * 
     **/
    protected function _formatSerieToHighcharts( $arrBillingHoursUsers ) { 
        
        if ( !empty( $arrBillingHoursUsers)) {

            $finalHcliteralSeries = "" ;
            $hcSeries = "";
            foreach ($arrBillingHoursUsers as $User => $arrBillingHours) {
                // Timestamp : 1357020060000 = January 1st, 2013
                $hcSeries = "{ name: '" . $User . "' , visible: false, data : [[1357020060000,0],";
                $dateVsHours = "";
                $billingHoursCumulative = 0;
                foreach ($arrBillingHours as $arrBillingHour) {
                    $billingHoursCumulative = $billingHoursCumulative + $arrBillingHour[1];
                    $dateVsHours .= "[" .$arrBillingHour[0].",".$billingHoursCumulative . "],";
                }
                $dateVsHours = rtrim( $dateVsHours, ',');
                $hcSeries .= $dateVsHours . "]} ,";
                $finalHcliteralSeries .= $hcSeries ;
            }
            $finalHcliteralSeries = rtrim( $finalHcliteralSeries, ",");      
        } else {
            $finalHcliteralSeries = "{ name : 'no Team Member on this project', data : [[1357020060000,0]]}";  
        }
        return $finalHcliteralSeries;
    }

    protected function _formatSerieToHighchartsTotalCost( $arrTotalBillingHours, $projectId ) { 
        $totalCostHcliteralSeries = "";
        if ( !empty( $arrTotalBillingHours)) {
            $budgetAsThreshold = $this->_getProjectBudget( $projectId);
            $totalCostHcliteralSeries = "{ name: 'Total Cost',showInLegend: false,  shadow: 'true' ,color : '#FF0000', negativeColor: '#339933' , threshold: " . $budgetAsThreshold . ", lineWidth: 4, data : [[1357020060000,0],";
            $totalCost = 0;
            // merge and add value for the same timestamp
            $arrTimestamp = array();
            foreach ( $arrTotalBillingHours as $key => $arrBillHour) {
                if ( $index = array_search($arrBillHour[0] , $arrTimestamp)) {
                    $arrTotalBillingHours[ $index] [1] = $arrTotalBillingHours[ $index] [1] + $arrBillHour [1];
                    unset($arrTotalBillingHours[ $key]);
                }
                 $arrTimestamp[] = $arrBillHour[0];
            }

            foreach ( $arrTotalBillingHours as $arrBillHour) {
                
                $totalCost = $totalCost + $arrBillHour[1];
                $totalCostHcliteralSeries =  $totalCostHcliteralSeries . "[" . $arrBillHour[0] . "," . $totalCost ."],";
                 
            }
            $totalCostHcliteralSeries = rtrim( $totalCostHcliteralSeries, ",");
            $totalCostHcliteralSeries = $totalCostHcliteralSeries . "]} ,";
        }
        return $totalCostHcliteralSeries;
    }

    protected function _formatBudgetSerieToHighcharts( $projectId) { 

        if ( isset( $projectId)) {
            $budget = $this->_getProjectBudget( $projectId);
            $BudgetHcliteralSeries = "{ name: 'Budget', color : '#ff4e50', data : [[1357020060000, $budget],[1378011600000, $budget]]},";   
        }else {
            return "";
        }
        return $BudgetHcliteralSeries;
    }

    protected function _getProjectBudget( $projectId){
        if ( isset( $projectId)) {
            $arrBudget = $this->Project->findById($projectId, array('project_budget'));
            $budget = $arrBudget['Project']['project_budget'];
        }
        return $budget;
    }

    protected function _calculateTotalCost( $arrTotalBillingHours ) { 

        if ( !empty( $arrTotalBillingHours)) {
           
            $totalCost = 0;

            foreach ( $arrTotalBillingHours as $arrBillHour) {
               
                $totalCost = $totalCost + $arrBillHour[1];    
            }
            return $totalCost;
        } else {
            return 0;
        }
        
    }
}
