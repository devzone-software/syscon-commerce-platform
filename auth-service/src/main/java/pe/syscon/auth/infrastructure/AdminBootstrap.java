package pe.syscon.auth.infrastructure;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.boot.ApplicationArguments;
import org.springframework.boot.ApplicationRunner;
import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;
import org.springframework.stereotype.Component;
import pe.syscon.auth.domain.*;
@Component public class AdminBootstrap implements ApplicationRunner {
 private final UserRepository users;private final RoleRepository roles;private final PermissionRepository permissions;private final String email,password;
 public AdminBootstrap(UserRepository users,RoleRepository roles,PermissionRepository permissions,@Value("${auth.admin-email:}") String email,@Value("${auth.admin-password:}") String password) {this.users=users;this.roles=roles;this.permissions=permissions;this.email=email;this.password=password;}
 @Override public void run(ApplicationArguments args) {
  if(email.isBlank()||password.isBlank()) return;
  if(password.length()<16 || password.startsWith("replace-with")) throw new IllegalStateException("ADMIN_PASSWORD must be a non-placeholder value of at least 16 characters");
  Role admin=roles.findById("ADMIN").orElseGet(() -> roles.save(new Role("ADMIN")));
  for(String name:new String[]{"catalog:write","orders:manage","payments:confirm","invoices:submit","suppliers:manage"}) admin.permissions.add(permissions.findById(name).orElseGet(() -> permissions.save(new Permission(name))));
  roles.save(admin);
  users.findByEmail(email.toLowerCase(java.util.Locale.ROOT)).orElseGet(() -> users.save(new User(email.toLowerCase(java.util.Locale.ROOT),new BCryptPasswordEncoder().encode(password),admin)));
 }
}
