jQuery(document).ready(function($) {
    $('.upload_file').click(function() {
        window.field = $(this).attr('id');
        tb_show('Upload a logo', 'media-upload.php?type=image&TB_iframe=true&post_id=0', false);
        return false;
    });

    // Display the Image link in TEXT Field7
    window.orig_ste = window.send_to_editor;
    window.send_to_editor = function(html) {
        
        if ($("#"+window.field+"_field").length < 1) {
            window.orig_ste(html);
        } else {
           ;
            $("#"+window.field+"_field").val(html);
            tb_remove();
        }
    }
});