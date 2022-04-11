<?php
/**
 * REDCap External Module: Edit Participant Survey Expiration
 * Replicate the Participant List page capability for editing survey expiry on data entry form.<br>Useful particularly in projects with a large number of records, where finding a specific record on the Participant List page is problematic.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\EditParticipantSurveyExpiration;

use ExternalModules\AbstractExternalModule;

class EditParticipantSurveyExpiration extends AbstractExternalModule
{
    public function redcap_data_entry_form_top($project_id, $record=null, $instrument, $event_id, $group_id=null, $repeat_instance=1) {
        global $Proj, $user_rights;

        if (!$user_rights['participants']) return;

        $surveyId = (array_key_exists('survey_id', $Proj->forms[$instrument])) ? $Proj->forms[$instrument]['survey_id'] : 0;
        if (empty($surveyId)) return;

        // Survey time limit enabled?
    	$timeLimit = \Survey::calculateSurveyTimeLimit($Proj->surveys[$surveyId]['survey_time_limit_days'], $Proj->surveys[$surveyId]['survey_time_limit_hours'], $Proj->surveys[$surveyId]['survey_time_limit_minutes']);
	    if ($timeLimit == 0) return;

        $this->initializeJavascriptModuleObject();
        $moduleName = $this->getJavascriptModuleObjectName();
        $moduleRef = str_replace('.','',$moduleName);

        $sql = "select p.participant_id, link_expiration
                from redcap_surveys s 
                inner join redcap_surveys_participants p on s.survey_id=p.survey_id
                inner join redcap_surveys_response r on p.participant_id=r.participant_id
                where s.survey_id = ? and p.event_id = ? and r.record=? limit 1";
        $q = $this->query($sql, [ $surveyId, $event_id, $record ]);
        while ($row = $q->fetch_assoc()) {
            $participant_id = $row['participant_id'];
            $linkExpiration = $row['link_expiration'];
        }

        if (empty($linkExpiration)) return;

		// If time limit enabled, then display icons if still open or expired
        $expirationDisplay = '-';
        $thisExpireTimestamp = \DateTimeRC::format_ts_from_ymd($linkExpiration);
        if (strtotime(NOW) > strtotime($linkExpiration)) {
            // If initial survey invite time + time limit is > now, then the link has expired
            $displayExpired = '';
            $displayExpTime = 'd-none';
        } else {
            // Not yet expired
            $displayExpired = 'd-none';
            $displayExpTime = '';
        }
        $expirationDisplay = \RCView::a(array('href'=>'javascript:;', 'onclick'=>"$moduleName.changeLinkExpiration($participant_id);"),
                \RCView::img(array('src'=>'cross-octagon.png', 'title'=>\RCView::tt('survey_1119', '').' '.$thisExpireTimestamp, 'class'=>"$moduleRef-ExpiryTime $displayExpired opacity65")).
                \RCView::img(array('src'=>'clock_fill.png'   , 'title'=>\RCView::tt('survey_1118', '').' '.$thisExpireTimestamp, 'class'=>"$moduleRef-ExpiryTime $displayExpTime"))
            );
        echo \RCView::div(
            array('id'=>"$moduleRef-ExpirationDisplay", 'class'=>'wrap d-none', 'style'=>'color:#777;'), 
            \RCView::tt('survey_1117')." <span style='font-size:10px;color:#666;'>".\RCView::tt('survey_1120')."</span> ".$expirationDisplay
        );
        
        ?>
        <script type="text/javascript">
            (function () {

                $(document).ready(function(){
                    if($('#form_response_header').length) {
                        $('#<?=$moduleRef?>-ExpirationDisplay')
                            .appendTo('#form_response_header')
                            .removeClass('d-none');
                    } else {
                        $('#<?=$moduleRef?>-ExpirationDisplay')
                            .insertAfter('#inviteFollowupSurveyBtn > div:eq(0)')
                            .css('float','right')
                            .css('padding','3px 20px 0 0')
                            .removeClass('d-none');
                    }
                });

                var module = <?=$moduleName?>;
                var langSave = '<?=js_escape(\RCView::tt('designate_forms_13',''))?>';
                var langLinkExpire1 = '<?=js_escape(\RCView::tt('survey_1121',''))?>';
                var langExpiredOn = '<?=js_escape(\RCView::tt('survey_1119',''))?>';
                var langExpireAt = '<?=js_escape(\RCView::tt('survey_1118',''))?>';
                var record = '<?=js_escape($record)?>';
                var pk = '<?=js_escape($Proj->metadata[$Proj->table_pk]['element_label'])?>';

                // Open dialog to change Link Expiration time (time limit)
                module.changeLinkExpiration = function(participant_id) {
                    $.post(app_path_webroot+"index.php?pid="+pid+"&route=SurveyController:changeLinkExpiration",{ participant_id: participant_id, action: 'view' },function(data) {
                        if (data == '' || data == '0') {
                            alert(woops);
                        } else {
                            // Display dialog
                            simpleDialog(data,langLinkExpire1,'linkExpirationDialog',650,null,window.lang.global_53,function(){
                                showProgress(1);
                                changeLinkExpirationSave(participant_id);
                            },langSave);
                            $('#changeLinkExpirationEmailDup').html('<span style="font-weight:bold;">'+record+'</span>');
                            // Enable link expiration datetime picker
                            $('#time_limit_expiration').datetimepicker({
                                onClose: function(dateText, inst){ $('#'+$(inst).attr('id')).blur(); },
                                buttonText: 'Click to select a date', yearRange: '-100:+10', changeMonth: true, changeYear: true, dateFormat: user_date_format_jquery,
                                hour: currentTime('h'), minute: currentTime('m'), buttonText: 'Click to select a date/time',
                                showOn: 'button', buttonImage: app_path_images+'datetime.png', buttonImageOnly: true, timeFormat: 'hh:mm', constrainInput: false
                            });
                        }
                    });
                }

                // Change Link Expiration time (time limit)
                function changeLinkExpirationSave(participant_id) {
                    var expTime = $('#time_limit_expiration').val();
                    var userDtFormat = '<?=strtolower(\DateTimeRC::get_user_format_base())?>';
                    $.post(app_path_webroot+"index.php?pid="+pid+"&route=SurveyController:changeLinkExpiration",{ time_limit_expiration: expTime, participant_id: participant_id, action: 'save' },function(data) {
                        if (data == '' || data == '0') {
                            alert(woops);
                        } else {
                            var diff = window.datediff(expTime.replace(/\//g, '-'), 'now', 's', userDtFormat, true);
                            var expired = (diff > 0);
                            $('#<?=$moduleRef?>-ExpirationDisplay img').each(function(i){
                                var t = $(this).attr('title');
                                $(this).attr('title', t.slice(0, -16) + expTime);
                                if (expired) {
                                    if (i==0) $(this).removeClass('d-none');
                                    if (i==1) $(this).addClass('d-none');
                                } else {
                                    if (i==0) $(this).addClass('d-none');
                                    if (i==1) $(this).removeClass('d-none');
                                }
                            });
                            showProgress(0,0);
                            simpleDialogAlt(data);
                        }
                    });
                }            
            })();
        </script>
        <?php
    }
}