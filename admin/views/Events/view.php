<code>
    <pre style="margin: 0;">
        <?php

        echo json_encode(
            json_decode((string) $oItem->data),
            JSON_PRETTY_PRINT
        );

        ?>
    </pre>
</code>
