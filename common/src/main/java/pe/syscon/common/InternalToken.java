package pe.syscon.common;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Component;

@Component
public class InternalToken {
    private final byte[] expected;
    public InternalToken(@Value("${security.service-token}") String value) {
        expected=value.getBytes(StandardCharsets.UTF_8);
        if(expected.length<32 || value.startsWith("replace-with")) throw new IllegalStateException("SERVICE_TOKEN must be a non-placeholder value of at least 32 bytes");
    }
    public void verify(String actual) {
        if(actual == null || !MessageDigest.isEqual(expected,actual.getBytes(StandardCharsets.UTF_8))) throw new ApiException(HttpStatus.UNAUTHORIZED,"Servicio no autorizado");
    }
}
