package pe.syscon.auth.application;
import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.ArgumentMatchers.*;
import static org.mockito.Mockito.*;
import java.util.*;
import org.junit.jupiter.api.Test;
import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;
import org.springframework.security.oauth2.jwt.NimbusJwtDecoder;
import javax.crypto.spec.SecretKeySpec;
import pe.syscon.auth.domain.*;
import pe.syscon.auth.infrastructure.*;
class AuthServiceTest {
 @Test void loginIssuesSignedAccessAndStoresOnlyRefreshHash() {
  var users=mock(UserRepository.class);var roles=mock(RoleRepository.class);var refresh=mock(RefreshTokenRepository.class);
  var user=new User("test@example.com",new BCryptPasswordEncoder().encode("long-secure-password"),new Role("CUSTOMER"));user.id=UUID.randomUUID();
  when(users.findByEmail("test@example.com")).thenReturn(Optional.of(user));
  String secret="a-long-random-secret-of-at-least-thirty-two-bytes";
  var tokens=new AuthService(users,roles,refresh,secret).login("test@example.com","long-secure-password");
  var jwt=NimbusJwtDecoder.withSecretKey(new SecretKeySpec(secret.getBytes(),"HmacSHA256")).build().decode(tokens.accessToken());
  assertEquals(user.id.toString(),jwt.getSubject());assertTrue(jwt.getClaimAsStringList("authorities").contains("ROLE_CUSTOMER"));
  verify(refresh).save(argThat(t -> !t.tokenHash.equals(tokens.refreshToken()) && t.tokenHash.length()>40));
 }
}
